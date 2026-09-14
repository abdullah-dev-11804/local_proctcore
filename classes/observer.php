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
     * Finalises capture when Moodle has durably submitted the Quiz attempt.
     *
     * Browser submission handling provides the fast path. This observer is the
     * authoritative fallback and must never make a valid Moodle submission fail.
     *
     * @param \mod_quiz\event\attempt_submitted $event Quiz attempt event.
     * @return void
     */
    public static function quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
        $sessions = new \local_proctorcore\local\session_repository();
        $session = $sessions->get_by_attempt_id((int) $event->objectid);
        if (!$session || in_array((string) $session->status,
                ['processing', 'completed', 'failed', 'abandoned', 'expired'], true)) {
            return;
        }

        try {
            $result = (new \local_proctorcore\local\capture_service())->stop_capture(
                (int) $session->id,
                null,
                'submitted'
            );
            (new \local_proctorcore\local\audit_logger())->log(
                'capture.quiz_attempt_submitted',
                (int) $session->companyid,
                (int) $session->id,
                (int) $session->userid,
                [
                    'attemptId' => (int) $session->attemptid,
                    'captureStatus' => (string) ($result['status'] ?? ''),
                    'eventTime' => (int) $event->timecreated,
                ],
                null,
                'session',
                (int) $session->id
            );
        } catch (\Throwable $exception) {
            $sessions->merge_server_metadata((int) $session->id, [
                'submissionFinalization' => [
                    'state' => 'retry_pending',
                    'eventTime' => (int) $event->timecreated,
                    'lastError' => clean_param($exception->getMessage(), PARAM_TEXT),
                    'lastAttemptAt' => time(),
                ],
            ]);
            debugging('ProctorCore could not finalise the submitted Quiz attempt: '
                . $exception->getMessage(), DEBUG_DEVELOPER);
        }
    }

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
