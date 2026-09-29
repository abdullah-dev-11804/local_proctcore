<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/** Captures an explicit, version-bound acknowledgement of quiz rules. */
final class rules_service {
    /**
     * Adds the always-visible warning for a quiz protected by ProctorCore.
     *
     * @param \MoodleQuickForm $mform Quiz preflight form.
     * @return void
     */
    public function add_proctoring_warning($mform): void {
        $mform->addElement('html', $this->render_proctoring_warning());
    }

    /**
     * Renders the warning on the Quiz overview before the preflight opens.
     *
     * @return string Localised warning markup.
     */
    public function render_proctoring_warning(): string {
        $items = '';
        foreach ([
            'preexamwarning:face',
            'preexamwarning:people',
            'preexamwarning:audio',
            'preexamwarning:device',
            'preexamwarning:equipment',
            'preexamwarning:materials',
        ] as $stringkey) {
            $items .= \html_writer::tag('li', get_string($stringkey, 'local_proctorcore'));
        }

        $content = \html_writer::tag(
            'h3',
            get_string('preexamwarning:title', 'local_proctorcore'),
            [
                'class' => 'local-proctorcore-exam-warning-title',
                'id' => 'local-proctorcore-exam-warning-title',
            ]
        );
        $content .= \html_writer::tag(
            'p',
            get_string('preexamwarning:intro', 'local_proctorcore'),
            ['class' => 'local-proctorcore-exam-warning-intro']
        );
        $content .= \html_writer::tag('ul', $items, ['class' => 'local-proctorcore-exam-warning-list']);
        $content .= \html_writer::tag(
            'p',
            get_string('preexamwarning:consequence', 'local_proctorcore'),
            ['class' => 'local-proctorcore-exam-warning-consequence']
        );
        $content .= \html_writer::tag(
            'p',
            get_string('preexamwarning:logging', 'local_proctorcore'),
            ['class' => 'local-proctorcore-exam-warning-logging']
        );
        return \html_writer::div($content, 'local-proctorcore-exam-warning', [
            'role' => 'note',
            'aria-labelledby' => 'local-proctorcore-exam-warning-title',
        ]);
    }

    public function add_preflight_field($mform, \stdClass $config): void {
        if (empty($config->requirerulesack) || trim((string) ($config->ruleshtml ?? '')) === '') {
            return;
        }
        $mform->addElement('header', 'proctorcore_rules_heading', get_string('rules:title', 'local_proctorcore'));
        $mform->addElement('static', 'proctorcore_rules_text', '',
            $this->render_rules((string) $config->ruleshtml));
        $mform->addElement('advcheckbox', 'proctorcore_rules_ack', get_string('rules:acknowledge', 'local_proctorcore'));
        $mform->setType('proctorcore_rules_ack', PARAM_BOOL);
        $mform->addRule('proctorcore_rules_ack', get_string('rules:required', 'local_proctorcore'), 'required', null, 'client');
    }

    /**
     * Renders pipe-separated rules as a compact responsive list while retaining
     * compatibility with existing formatted rule text.
     *
     * @param string $ruleshtml Configured rule text.
     * @return string Rendered rules markup.
     */
    private function render_rules(string $ruleshtml): string {
        if (strpos($ruleshtml, '|') === false) {
            return \html_writer::div(format_text($ruleshtml, FORMAT_HTML), 'local-proctorcore-rules');
        }

        $items = '';
        $ruletext = html_to_text($ruleshtml, 0, false);
        foreach (preg_split('/\s*\|\s*/u', $ruletext) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            $items .= \html_writer::tag('li', s($rule));
        }

        return \html_writer::div(
            \html_writer::tag('ul', $items, ['class' => 'local-proctorcore-rules-list']),
            'local-proctorcore-rules local-proctorcore-rules-piped'
        );
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
