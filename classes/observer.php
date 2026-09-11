<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/**
 * Minimal Moodle event observers for lifecycle cleanup.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer {
    /**
     * Deletes the reusable Server B face reference when Moodle deletes a user.
     *
     * @param \core\event\user_deleted $event Moodle user-deleted event.
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        (new \local_proctorcore\local\identity_service())->erase_reference(
            (int) $event->objectid,
            'moodle_user_deleted',
            null
        );
    }

    /** Releases appeal evidence after the learner fully completes the course. */
    public static function course_completed(\core\event\course_completed $event): void {
        global $DB;
        $sql = "SELECT DISTINCT s.id
                  FROM {local_proctorcore_sessions} s
                  JOIN {local_proctorcore_appeals} a ON a.sessionid = s.id
                 WHERE s.userid = :userid AND s.courseid = :courseid
                   AND a.status <> :withdrawn AND s.appealstatus <> :released";
        $sessions = $DB->get_records_sql($sql, [
            'userid' => (int) $event->relateduserid,
            'courseid' => (int) $event->courseid,
            'withdrawn' => 'withdrawn',
            'released' => 'released',
        ]);
        foreach ($sessions as $session) {
            $DB->set_field('local_proctorcore_sessions', 'appealstatus', 'release_pending', [
                'id' => (int) $session->id,
            ]);
            try {
                (new \local_proctorcore\local\appeal_service())->release_session_evidence(
                    (int) $session->id,
                    (int) $event->timecreated,
                    'course_completed',
                    (int) $event->relateduserid
                );
            } catch (\Throwable $exception) {
                debugging('ProctorCore could not release completed-course evidence: '
                    . $exception->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
