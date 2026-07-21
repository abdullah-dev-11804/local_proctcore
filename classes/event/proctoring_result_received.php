<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event fired after a valid final Passed/Failed result is stored in Moodle.
 *
 * Other components, including an exam-protocol integration, can observe this
 * event without coupling themselves directly to the webhook implementation.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class proctoring_result_received extends \core\event\base {
    /**
     * Initialises event metadata.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_proctorcore_sessions';
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event:proctoringresultreceived', 'local_proctorcore');
    }

    /**
     * Event description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'Server B proctoring result "' . $this->other['result']
            . '" was stored for ProctorCore session ' . $this->objectid
            . ' and quiz attempt ' . $this->other['attemptid'] . '.';
    }

    /**
     * Link to the future report page.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/local/proctorcore/reports.php', ['sessionid' => $this->objectid]);
    }

    /**
     * Validates event data during development.
     *
     * @return void
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (empty($this->relateduserid)) {
            throw new \coding_exception('relateduserid is required.');
        }
        foreach (['server_sessionid', 'server_eventid', 'attemptid', 'quizid', 'companyid', 'result', 'status'] as $key) {
            if (!array_key_exists($key, $this->other)) {
                throw new \coding_exception('Missing event other field: ' . $key);
            }
        }
    }
}
