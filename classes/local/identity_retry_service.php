<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/** Persists quiz-scoped identity failures and timed lockouts. */
final class identity_retry_service {
    private const TABLE = 'local_proctorcore_idretry';

    /** Returns the current retry state, clearing an expired window. */
    public function get_status(int $companyid, int $userid, int $quizid, int $limit, int $windowseconds): array {
        global $DB;

        $limit = min(20, max(1, $limit));
        $windowseconds = min(DAYSECS, max(MINSECS, $windowseconds));
        $record = $DB->get_record(self::TABLE, [
            'companyid' => max(0, $companyid),
            'userid' => $userid,
            'quizid' => $quizid,
        ]);
        if ($record && (int) $record->resetat <= time()) {
            // Include the observed expiry so a concurrent failure that extends the window is not deleted.
            $DB->delete_records(self::TABLE, [
                'id' => (int) $record->id,
                'resetat' => (int) $record->resetat,
            ]);
            $record = $DB->get_record(self::TABLE, [
                'companyid' => max(0, $companyid),
                'userid' => $userid,
                'quizid' => $quizid,
            ]);
        }
        return $this->state($record ?: null, $limit, $windowseconds);
    }

    /** Records one Moodle-denied identity attempt and extends its reset window. */
    public function record_failure(
        int $companyid,
        int $userid,
        int $quizid,
        int $limit,
        int $windowseconds
    ): array {
        global $DB;

        $companyid = max(0, $companyid);
        $limit = min(20, max(1, $limit));
        $windowseconds = min(DAYSECS, max(MINSECS, $windowseconds));
        $factory = \core\lock\lock_config::get_lock_factory('local_proctorcore_identity_retry');
        $lock = $factory->get_lock("{$companyid}:{$userid}:{$quizid}", 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'error');
        }
        try {
            $now = time();
            $record = $DB->get_record(self::TABLE, [
                'companyid' => $companyid,
                'userid' => $userid,
                'quizid' => $quizid,
            ]);
            if ($record && (int) $record->resetat <= $now) {
                $DB->delete_records(self::TABLE, ['id' => (int) $record->id]);
                $record = false;
            }
            if ($record) {
                $record->failures = min($limit, max(0, (int) $record->failures) + 1);
                $record->resetat = $now + $windowseconds;
                $record->lastfailedat = $now;
                $record->timemodified = $now;
                $DB->update_record(self::TABLE, $record);
            } else {
                $record = (object) [
                    'companyid' => $companyid,
                    'userid' => $userid,
                    'quizid' => $quizid,
                    'failures' => 1,
                    'resetat' => $now + $windowseconds,
                    'lastfailedat' => $now,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $record->id = $DB->insert_record(self::TABLE, $record);
            }
            return $this->state($record, $limit, $windowseconds);
        } finally {
            $lock->release();
        }
    }

    /** Clears failures after a successful identity check. */
    public function clear(int $companyid, int $userid, int $quizid): void {
        global $DB;
        $DB->delete_records(self::TABLE, [
            'companyid' => max(0, $companyid),
            'userid' => $userid,
            'quizid' => $quizid,
        ]);
    }

    /** Builds the browser-safe retry state. */
    private function state(?\stdClass $record, int $limit, int $windowseconds): array {
        $failures = $record ? min($limit, max(0, (int) $record->failures)) : 0;
        $resetat = $record ? max(time(), (int) $record->resetat) : 0;
        return [
            'failedAttempts' => $failures,
            'maxAttempts' => $limit,
            'attemptsRemaining' => max(0, $limit - $failures),
            'locked' => $failures >= $limit,
            'resetAt' => $resetat,
            'retryAfterSeconds' => $resetat ? max(0, $resetat - time()) : 0,
            'windowSeconds' => $windowseconds,
        ];
    }
}
