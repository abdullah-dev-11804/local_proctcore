<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Owns reads and writes for official proctoring session records.
 */
final class session_repository {
    public const TABLE = 'local_proctorcore_sessions';

    /**
     * Creates a new proctoring session linked to a quiz attempt.
     *
     * @param int $companyid IOMAD company ID (0 for site level).
     * @param int $courseid Moodle course ID.
     * @param int $cmid Course module ID.
     * @param int $quizid Quiz ID.
     * @param int $attemptid Quiz attempt ID.
     * @param int $userid User ID.
     * @return int The ID of the newly created session record.
     */
    public static function create_session(
        int $companyid,
        int $courseid,
        int $cmid,
        int $quizid,
        int $attemptid,
        int $userid
    ): int {
        global $DB;

        $now = time();
        $record = new \stdClass();
        $record->companyid = $companyid;
        $record->courseid = $courseid;
        $record->cmid = $cmid;
        $record->quizid = $quizid;
        $record->attemptid = $attemptid;
        $record->userid = $userid;
        
        // Default statuses based on your install.xml schema
        $record->status = 'created';
        $record->result = 'unknown';
        $record->identitystatus = 'pending';
        $record->techcheckstatus = 'pending';
        $record->appealstatus = 'none';
        $record->violationcount = 0;
        $record->snapshotcount = 0;
        $record->timecreated = $now;
        $record->timemodified = $now;

        return $DB->insert_record(self::TABLE, $record);
    }

    /**
     * Retrieves a session by its associated quiz attempt ID.
     *
     * @param int $attemptid The quiz attempt ID.
     * @return \stdClass|false The session record, or false if not found.
     */
    public static function get_by_attempt(int $attemptid) {
        global $DB;
        return $DB->get_record(self::TABLE, ['attemptid' => $attemptid]);
    }

    /**
     * Updates an existing session record.
     *
     * @param \stdClass $session The session record to update (must include 'id').
     * @return bool True on success.
     */
    public static function update_session(\stdClass $session): bool {
        global $DB;
        $session->timemodified = time();
        return $DB->update_record(self::TABLE, $session);
    }
}