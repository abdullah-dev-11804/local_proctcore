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
        $conditions = ['companyid' => $companyid];
        if ($activeonly) {
            $conditions['active'] = 1;
        }
        return array_values($DB->get_records('local_proctorcore_fields', $conditions, 'sortorder, id'));
    }

    /** Adds configured fields to Moodle's preflight form. */
    public function add_preflight_fields($mform, int $companyid, int $userid): void {
        $fields = $this->get_fields($companyid);
        if (!$fields) {
            return;
        }
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
        $config = json_decode((string) ($field->configjson ?? ''), true);
        $values = is_array($config['options'] ?? null) ? $config['options'] : [];
        return $values ? array_combine(array_map('strval', $values), array_map('strval', $values)) : [];
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
        global $DB;
        $record = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $fieldid]);
        if ($record) {
            $record->data = $value;
            $record->dataformat = FORMAT_PLAIN;
            $DB->update_record('user_info_data', $record);
        } else {
            $DB->insert_record('user_info_data', (object) [
                'userid' => $userid, 'fieldid' => $fieldid, 'data' => $value, 'dataformat' => FORMAT_PLAIN,
            ]);
        }
    }
}
