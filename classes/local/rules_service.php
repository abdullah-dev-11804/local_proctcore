<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/** Captures an explicit, version-bound acknowledgement of quiz rules. */
final class rules_service {
    public function add_preflight_field($mform, \stdClass $config): void {
        if (empty($config->requirerulesack) || trim((string) ($config->ruleshtml ?? '')) === '') {
            return;
        }
        $mform->addElement('header', 'proctorcore_rules_heading', get_string('rules:title', 'local_proctorcore'));
        $mform->addElement('static', 'proctorcore_rules_text', '',
            \html_writer::div(format_text((string) $config->ruleshtml, FORMAT_HTML), 'local-proctorcore-rules'));
        $mform->addElement('advcheckbox', 'proctorcore_rules_ack', get_string('rules:acknowledge', 'local_proctorcore'));
        $mform->setType('proctorcore_rules_ack', PARAM_BOOL);
        $mform->addRule('proctorcore_rules_ack', get_string('rules:required', 'local_proctorcore'), 'required', null, 'client');
    }

    public function validate_and_remember(array $data, int $quizid, int $userid, \stdClass $config): array {
        global $SESSION;
        if (empty($config->requirerulesack) || trim((string) ($config->ruleshtml ?? '')) === '') {
            return [];
        }
        if (empty($data['proctorcore_rules_ack'])) {
            return ['proctorcore_rules_ack' => get_string('rules:required', 'local_proctorcore')];
        }
        $SESSION->local_proctorcore_rules[$quizid][$userid] = [
            'hash' => hash('sha256', (string) $config->ruleshtml),
            'language' => current_language(),
            'acknowledgedat' => time(),
            'expires' => time() + HOURSECS,
        ];
        return [];
    }

    public function persist_for_session(int $sessionid, int $quizid, int $userid): void {
        global $DB, $SESSION;
        $pending = $SESSION->local_proctorcore_rules[$quizid][$userid] ?? null;
        if (!$pending || (int) ($pending['expires'] ?? 0) < time()) {
            return;
        }
        if (!$DB->record_exists('local_proctorcore_rulesack', ['sessionid' => $sessionid, 'userid' => $userid])) {
            $DB->insert_record('local_proctorcore_rulesack', (object) [
                'sessionid' => $sessionid,
                'userid' => $userid,
                'language' => clean_param((string) $pending['language'], PARAM_LANG),
                'ruleshash' => (string) $pending['hash'],
                'ipaddress' => getremoteaddr() ?: null,
                'useragent' => clean_param($_SERVER['HTTP_USER_AGENT'] ?? '', PARAM_TEXT),
                'acknowledgedat' => (int) $pending['acknowledgedat'],
                'timecreated' => time(),
            ]);
        }
        unset($SESSION->local_proctorcore_rules[$quizid][$userid]);
    }
}
