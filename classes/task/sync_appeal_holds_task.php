<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\task;

defined('MOODLE_INTERNAL') || die();

/** Retries remote evidence holds that were accepted locally during an outage. */
final class sync_appeal_holds_task extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task:syncappealholds', 'local_proctorcore');
    }

    public function execute(): void {
        global $DB;
        $records = $DB->get_records('local_proctorcore_appeals', ['status' => 'hold_pending'], 'timemodified ASC', 'id', 0, 100);
        $service = new \local_proctorcore\local\appeal_service();
        foreach ($records as $record) {
            $service->sync_hold((int) $record->id);
        }

        $sql = "SELECT s.id, s.userid, s.courseid, cc.timecompleted
                  FROM {local_proctorcore_sessions} s
                  JOIN {course_completions} cc
                    ON cc.userid = s.userid AND cc.course = s.courseid
                 WHERE s.appealstatus = :pending AND cc.timecompleted IS NOT NULL";
        foreach ($DB->get_records_sql($sql, ['pending' => 'release_pending']) as $session) {
            try {
                $service->release_session_evidence((int) $session->id, (int) $session->timecompleted,
                    'course_completion_retry', 0);
            } catch (\Throwable $exception) {
                debugging('ProctorCore appeal release will be retried: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
