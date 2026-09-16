<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/** Configures, prefills, validates, and snapshots reusable participant data. */
final class participant_field_service {
    private const PREFIX = 'proctorcore_participant_';

    /** @return \stdClass[] */
    public function get_fields(int $companyid, bool $activeonly = true): array {
        global $DB;

        // A.4 is deliberately site-wide. Keep the company argument for API
        // compatibility with callers, but never add legacy tenant definitions
        // to the globally selected pre-exam fields.
        $where = 'companyid = :participantcompany';
        $params = ['participantcompany' => 0];
        if ($activeonly) {
            $where .= ' AND active = 1';
        }
        $records = array_values($DB->get_records_select(
            'local_proctorcore_fields',
            $where,
            $params,
            'companyid ASC, sortorder ASC, id ASC'
        ));

        if ($activeonly) {
            // A Moodle profile field may have been deleted outside ProctorCore.
            // Do not block exam entry with a configuration that can no longer
            // be displayed or saved; the admin board still exposes it for removal.
            $profileids = array_values(array_unique(array_filter(array_map(
                static fn(\stdClass $record): int => (int) $record->profilefieldid,
                $records
            ))));
            $availableprofiles = $profileids
                ? $DB->get_records_list('user_info_field', 'id', $profileids, '', 'id')
                : [];
            $records = array_values(array_filter(
                $records,
                static fn(\stdClass $record): bool => empty($record->profilefieldid)
                    || isset($availableprofiles[(int) $record->profilefieldid])
            ));
        }
        usort($records, static function(\stdClass $left, \stdClass $right): int {
            return [(int) $left->sortorder, (int) $left->id]
                <=> [(int) $right->sortorder, (int) $right->id];
        });
        return $records;
    }

    /** Adds configured fields to Moodle's preflight form. */
    public function add_preflight_fields($mform, int $companyid, int $userid): void {
        $fields = $this->get_fields($companyid);
        if (!$fields) {
            return;
        }
        $mform->addElement('html', '<div class="local-proctorcore-participant-fields is-waiting" '
            . 'data-proctorcore-participant-fields>');
        $mform->addElement('header', 'proctorcore_participant_heading',
            get_string('participant:preflightheading', 'local_proctorcore'));
        foreach ($fields as $field) {
            $name = self::PREFIX . (int) $field->id;
            $label = $this->localized($field, 'name');
            switch ((string) $field->datatype) {
                case 'number':
                    $mform->addElement('text', $name, $label);
                    $mform->setType($name, PARAM_RAW_TRIMMED);
                    break;
                case 'date':
                    $mform->addElement('date_selector', $name, $label, ['optional' => empty($field->required)]);
                    $mform->setType($name, PARAM_INT);
                    break;
                case 'dropdown':
                    $options = $this->options($field);
                    if (empty($field->required)) {
                        $options = ['' => get_string('choosedots')] + $options;
                    }
                    $mform->addElement('select', $name, $label, $options);
                    $mform->setType($name, PARAM_TEXT);
                    break;
                case 'checkbox':
                    $mform->addElement('advcheckbox', $name, $label);
                    $mform->setType($name, PARAM_BOOL);
                    break;
                default:
                    $mform->addElement('text', $name, $label);
                    $mform->setType($name, PARAM_TEXT);
            }
            $help = $this->localized($field, 'help');
            if ($help !== '') {
                $mform->addElement('static', $name . '_help', '', s($help));
            }
            $mform->setDefault($name, $this->get_user_value($field, $companyid, $userid));
            if (!empty($field->required) && (string) $field->datatype !== 'checkbox') {
                $mform->addRule($name, null, 'required', null, 'client');
            }
            if (empty($field->editablebyuser)) {
                $mform->freeze($name);
            }
        }
        $mform->addElement('html', '</div>');
    }

    /** Returns all standard Moodle custom profile fields available for inclusion. */
    public function get_profile_fields(): array {
        global $DB;
        if (!$DB->get_manager()->table_exists(new \xmldb_table('user_info_field'))) {
            return [];
        }
        return array_values($DB->get_records('user_info_field', [], 'sortorder, id'));
    }

    /** Includes a Moodle custom profile field in every proctored exam. */
    public function include_profile_field(int $profilefieldid): \stdClass {
        global $DB;

        $profile = $DB->get_record('user_info_field', ['id' => $profilefieldid], '*', MUST_EXIST);
        $matches = $DB->get_records('local_proctorcore_fields', [
            'companyid' => 0,
            'profilefieldid' => $profilefieldid,
        ], 'id ASC', '*', 0, 1);
        $existing = $matches ? reset($matches) : false;
        if (!$existing) {
            $existing = $DB->get_record('local_proctorcore_fields', [
                'companyid' => 0,
                'shortname' => (string) $profile->shortname,
            ]);
        }
        $sortorder = (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(sortorder), 0) FROM {local_proctorcore_fields} WHERE companyid = 0'
        ) + 10;
        $datatype = $this->profile_datatype((string) $profile->datatype);
        $options = $datatype === 'dropdown'
            ? trim((string) ($profile->param1 ?? ''))
            : '';
        $description = trim(html_to_text((string) ($profile->description ?? ''), 0, false));

        return $this->save_definition([
            'id' => (int) ($existing->id ?? 0),
            'companyid' => 0,
            'profilefieldid' => $profilefieldid,
            'shortname' => (string) $profile->shortname,
            'name' => (string) $profile->name,
            'nameru' => (string) ($existing->nameru ?? $profile->name),
            'namekk' => (string) ($existing->namekk ?? $profile->name),
            'helptext' => (string) ($existing->helptext ?? $description),
            'helpru' => (string) ($existing->helpru ?? $description),
            'helpkk' => (string) ($existing->helpkk ?? $description),
            'datatype' => (string) ($existing->datatype ?? $datatype),
            'options' => $existing ? implode("\n", $this->option_values($existing)) : $options,
            'required' => $existing ? (int) $existing->required : (int) ($profile->required ?? 0),
            'editablebyuser' => $existing ? (int) $existing->editablebyuser : empty($profile->locked),
            'active' => 1,
            'sortorder' => $existing ? (int) $existing->sortorder : $sortorder,
        ]);
    }

    /** Reorders one global proctoring field while retaining stable integer positions. */
    public function move_definition(int $id, string $direction): void {
        global $DB;

        $records = array_values($DB->get_records(
            'local_proctorcore_fields',
            ['companyid' => 0, 'active' => 1],
            'sortorder, id'
        ));
        $index = null;
        foreach ($records as $position => $record) {
            if ((int) $record->id === $id) {
                $index = $position;
                break;
            }
        }
        if ($index === null) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $target = $direction === 'up' ? $index - 1 : ($direction === 'down' ? $index + 1 : $index);
        if ($target >= 0 && $target < count($records) && $target !== $index) {
            [$records[$index], $records[$target]] = [$records[$target], $records[$index]];
        }
        $transaction = $DB->start_delegated_transaction();
        foreach ($records as $position => $record) {
            $DB->set_field('local_proctorcore_fields', 'sortorder', ($position + 1) * 10, ['id' => $record->id]);
        }
        $transaction->allow_commit();
    }

    /** Validates and retains values until Moodle creates the attempt/session. */
    public function validate_and_remember(array $data, int $quizid, int $companyid, int $userid): array {
        global $SESSION;
        $errors = [];
        $values = [];
        foreach ($this->get_fields($companyid) as $field) {
            $name = self::PREFIX . (int) $field->id;
            $raw = empty($field->editablebyuser) ? $this->get_user_value($field, $companyid, $userid) : ($data[$name] ?? null);
            $value = $this->normalise_value($field, $raw, $errors, $name);
            if (!empty($field->required) && ($value === '' || ((string) $field->datatype === 'checkbox' && $value !== '1'))) {
                $errors[$name] = get_string('participant:requiredvalue', 'local_proctorcore');
            }
            $values[(int) $field->id] = $value;
        }
        if (!$errors) {
            $SESSION->local_proctorcore_participant[$quizid][$userid] = [
                'companyid' => $companyid, 'values' => $values, 'expires' => time() + HOURSECS,
            ];
        }
        return $errors;
    }

    /** Writes reusable values and an immutable session snapshot. */
    public function persist_for_session(int $sessionid, int $quizid, int $userid): void {
        global $DB, $SESSION;
        $session = $DB->get_record('local_proctorcore_sessions', ['id' => $sessionid, 'userid' => $userid], '*', MUST_EXIST);
        $pending = $SESSION->local_proctorcore_participant[$quizid][$userid] ?? null;
        if (!$pending || (int) ($pending['companyid'] ?? -1) !== (int) $session->companyid
                || (int) ($pending['expires'] ?? 0) < time()) {
            return;
        }
        $transaction = $DB->start_delegated_transaction();
        foreach ($this->get_fields((int) $session->companyid) as $field) {
            $fieldid = (int) $field->id;
            $value = (string) ($pending['values'][$fieldid] ?? '');
            $this->upsert('local_proctorcore_uservals', [
                'companyid' => (int) $session->companyid, 'userid' => $userid, 'fieldid' => $fieldid,
            ], $value);
            $this->upsert('local_proctorcore_fieldvals', [
                'sessionid' => $sessionid, 'userid' => $userid, 'fieldid' => $fieldid,
            ], $value);
            if (!empty($field->profilefieldid)) {
                $this->save_profile_value((int) $field->profilefieldid, $userid, $value);
            }
        }
        unset($SESSION->local_proctorcore_participant[$quizid][$userid]);
        $transaction->allow_commit();
    }

    /** Saves a field definition; old session snapshots remain intact. */
    public function save_definition(array $data): \stdClass {
        global $DB;
        $id = (int) ($data['id'] ?? 0);
        $record = $id ? $DB->get_record('local_proctorcore_fields', ['id' => $id], '*', MUST_EXIST) : new \stdClass();
        $now = time();
        $record->companyid = (int) ($data['companyid'] ?? 0);
        $record->sortorder = max(0, (int) ($data['sortorder'] ?? 0));
        $record->profilefieldid = empty($data['profilefieldid']) ? null : (int) $data['profilefieldid'];
        foreach (['shortname', 'name', 'namekk', 'nameru', 'helptext', 'helpkk', 'helpru', 'datatype'] as $name) {
            $record->{$name} = clean_param((string) ($data[$name] ?? ''), $name === 'shortname' ? PARAM_ALPHANUMEXT : PARAM_TEXT);
        }
        foreach (['required', 'editablebyuser', 'active'] as $name) {
            $record->{$name} = empty($data[$name]) ? 0 : 1;
        }
        $options = preg_split('/\R+/', trim((string) ($data['options'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $record->configjson = json_encode(['options' => array_values(array_map('trim', $options))], JSON_UNESCAPED_UNICODE);
        $record->timemodified = $now;
        if ($id) {
            $DB->update_record('local_proctorcore_fields', $record);
        } else {
            $record->timecreated = $now;
            $record->id = $DB->insert_record('local_proctorcore_fields', $record);
        }
        return $record;
    }

    /** Deactivates used fields and permanently removes definitions with no values. */
    public function delete_definition(int $id): void {
        global $DB;
        if ($DB->record_exists('local_proctorcore_fieldvals', ['fieldid' => $id])
                || $DB->record_exists('local_proctorcore_uservals', ['fieldid' => $id])) {
            $DB->set_field('local_proctorcore_fields', 'active', 0, ['id' => $id]);
        } else {
            $DB->delete_records('local_proctorcore_fields', ['id' => $id]);
        }
    }

    private function get_user_value(\stdClass $field, int $companyid, int $userid): string {
        global $DB;
        if (!empty($field->profilefieldid)) {
            $value = $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => (int) $field->profilefieldid]);
            if ($value !== false && $value !== '') {
                return (string) $value;
            }
        }
        $value = $DB->get_field('local_proctorcore_uservals', 'value', [
            'companyid' => $companyid, 'userid' => $userid, 'fieldid' => (int) $field->id,
        ]);
        return $value === false ? '' : (string) $value;
    }

    private function normalise_value(\stdClass $field, $raw, array &$errors, string $name): string {
        if ((string) $field->datatype === 'checkbox') {
            return empty($raw) ? '0' : '1';
        }
        if ((string) $field->datatype === 'date') {
            return empty($raw) ? '' : (string) max(0, (int) $raw);
        }
        $value = trim((string) $raw);
        if ((string) $field->datatype === 'number' && $value !== '' && !is_numeric($value)) {
            $errors[$name] = get_string('participant:invalidnumber', 'local_proctorcore');
        } else if ((string) $field->datatype === 'dropdown' && $value !== ''
                && !array_key_exists($value, $this->options($field))) {
            $errors[$name] = get_string('participant:invalidoption', 'local_proctorcore');
        }
        return clean_param($value, PARAM_TEXT);
    }

    private function localized(\stdClass $field, string $kind): string {
        $lang = substr(current_language(), 0, 2);
        $property = $kind === 'name' ? ($lang === 'kk' ? 'namekk' : ($lang === 'ru' ? 'nameru' : 'name'))
            : ($lang === 'kk' ? 'helpkk' : ($lang === 'ru' ? 'helpru' : 'helptext'));
        $fallback = $kind === 'name' ? 'name' : 'helptext';
        return trim((string) ($field->{$property} ?? '')) ?: trim((string) ($field->{$fallback} ?? ''));
    }

    private function options(\stdClass $field): array {
        $values = $this->option_values($field);
        return $values ? array_combine(array_map('strval', $values), array_map('strval', $values)) : [];
    }

    /** @return string[] */
    private function option_values(\stdClass $field): array {
        // Moodle is authoritative for mapped custom profile fields. Administrators
        // edit menu choices in the standard profile-field screen, often after the
        // field was first included in ProctorCore, so do not rely on the old copy.
        $profileoptions = $this->profile_option_values($field);
        if ($profileoptions) {
            return $profileoptions;
        }
        $config = json_decode((string) ($field->configjson ?? ''), true);
        return is_array($config['options'] ?? null)
            ? $this->normalise_options($config['options'])
            : [];
    }

    /** Reads the latest choices from a mapped Moodle menu profile field. */
    private function profile_option_values(\stdClass $field): array {
        global $DB;

        if (empty($field->profilefieldid)) {
            return [];
        }
        $profile = $DB->get_record(
            'user_info_field',
            ['id' => (int) $field->profilefieldid],
            'id,datatype,param1'
        );
        if (!$profile || strtolower((string) $profile->datatype) !== 'menu') {
            return [];
        }
        $options = preg_split('/\R+/', trim((string) $profile->param1), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return $this->normalise_options($options);
    }

    /** Trims empty and duplicate dropdown choices while retaining their order. */
    private function normalise_options(array $options): array {
        $normalised = [];
        foreach ($options as $option) {
            $value = trim((string) $option);
            if ($value !== '' && !isset($normalised[$value])) {
                $normalised[$value] = $value;
            }
        }
        return array_values($normalised);
    }

    private function upsert(string $table, array $keys, string $value): void {
        global $DB;
        $record = $DB->get_record($table, $keys);
        $now = time();
        if ($record) {
            $record->value = $value;
            $record->timemodified = $now;
            $DB->update_record($table, $record);
        } else {
            $DB->insert_record($table, (object) ($keys + ['value' => $value, 'timecreated' => $now, 'timemodified' => $now]));
        }
    }

    private function save_profile_value(int $fieldid, int $userid, string $value): void {
        global $CFG, $DB;
        $profile = $DB->get_record('user_info_field', ['id' => $fieldid], 'id,shortname', MUST_EXIST);
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $user = (object) [
            'id' => $userid,
            'profile_field_' . (string) $profile->shortname => $value,
        ];
        profile_save_data($user);
    }

    /** Maps Moodle's profile plugins to the field types supported by A.4.1. */
    private function profile_datatype(string $datatype): string {
        $map = [
            'checkbox' => 'checkbox',
            'datetime' => 'date',
            'menu' => 'dropdown',
        ];
        return $map[strtolower($datatype)] ?? 'text';
    }
}
