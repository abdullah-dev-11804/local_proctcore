<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Owns reads and writes for official proctoring session records.
<<<<<<< HEAD
 */
final class session_repository {
    public const TABLE = 'local_proctorcore_sessions';
=======
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class session_repository {
    /** Session table. */
    public const TABLE = 'local_proctorcore_sessions';

    /**
     * Creates a local session or returns the existing attempt mapping.
     *
     * @param array $data Required session fields.
     * @return \stdClass
     */
    public function create_or_get(array $data): \stdClass {
        global $DB;

        if (!array_key_exists('companyid', $data) || (int) $data['companyid'] < 0) {
            throw new \coding_exception('Missing or invalid session field: companyid');
        }
        foreach (['courseid', 'cmid', 'quizid', 'attemptid', 'userid'] as $required) {
            if (empty($data[$required])) {
                throw new \coding_exception('Missing required session field: ' . $required);
            }
        }

        $existing = $DB->get_record(self::TABLE, [
            'companyid' => (int) $data['companyid'],
            'attemptid' => (int) $data['attemptid'],
        ]);
        if ($existing) {
            return $existing;
        }

        $now = time();
        $record = (object) [
            'companyid' => (int) $data['companyid'],
            'courseid' => (int) $data['courseid'],
            'cmid' => (int) $data['cmid'],
            'quizid' => (int) $data['quizid'],
            'attemptid' => (int) $data['attemptid'],
            'userid' => (int) $data['userid'],
            'server_sessionid' => null,
            'server_roomid' => null,
            'launch_token_hash' => null,
            'status' => 'created',
            'result' => 'unknown',
            'identitystatus' => 'pending',
            'techcheckstatus' => 'pending',
            'appealstatus' => 'none',
            'risk_score' => null,
            'violationcount' => 0,
            'snapshotcount' => 0,
            'startedat' => null,
            'endedat' => null,
            'lastheartbeat' => null,
            'appealuntil' => null,
            'reportexpiresat' => null,
            'videoexpiresat' => null,
            'closedreason' => null,
            'servermetadata' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $record->id = $DB->insert_record(self::TABLE, $record);
        return $record;
    }

    /**
     * Gets a session by local id.
     *
     * @param int $sessionid Local session id.
     * @param int $strictness Moodle strictness constant.
     * @return \stdClass|false
     */
    public function get_by_id(int $sessionid, int $strictness = MUST_EXIST) {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $sessionid], '*', $strictness);
    }

    /**
     * Gets a session by attempt and company.
     *
     * @param int $attemptid Quiz attempt id.
     * @param int|null $companyid Optional tenant id.
     * @return \stdClass|null
     */
    public function get_by_attempt_id(int $attemptid, ?int $companyid = null): ?\stdClass {
        global $DB;
        $conditions = ['attemptid' => $attemptid];
        if ($companyid !== null) {
            $conditions['companyid'] = $companyid;
        }
        return $DB->get_record(self::TABLE, $conditions) ?: null;
    }

    /**
     * Gets a session by attempt and owner.
     *
     * @param int $attemptid Quiz attempt id.
     * @param int $userid Moodle user id.
     * @return \stdClass|null
     */
    public function get_by_attempt_and_user(int $attemptid, int $userid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, [
            'attemptid' => $attemptid,
            'userid' => $userid,
        ]) ?: null;
    }

    /**
     * Gets a session by Server B id.
     *
     * @param string $serversessionid External session id.
     * @return \stdClass|null
     */
    public function get_by_server_session_id(string $serversessionid): ?\stdClass {
        global $DB;
        return $DB->get_record(self::TABLE, ['server_sessionid' => $serversessionid]) ?: null;
    }

    /**
     * Finds the local session referenced by a Server B event.
     *
     * @param string $serversessionid Server B session id.
     * @param int|null $moodlesessionid Optional local session id.
     * @return \stdClass|null
     */
    public function find_for_webhook(string $serversessionid, ?int $moodlesessionid = null): ?\stdClass {
        $session = $serversessionid !== ''
            ? $this->get_by_server_session_id($serversessionid)
            : null;

        if (!$session && $moodlesessionid !== null && $moodlesessionid > 0) {
            $candidate = $this->get_by_id($moodlesessionid, IGNORE_MISSING);
            if ($candidate && ($serversessionid === '' || empty($candidate->server_sessionid)
                    || hash_equals((string) $candidate->server_sessionid, $serversessionid))) {
                $session = $candidate;
            }
        }

        return $session ?: null;
    }

    /**
     * Binds a local session to the Server B session.
     *
     * @param int $sessionid Local session id.
     * @param string $serversessionid Server B session id.
     * @param string|null $serverroomid Optional room id.
     * @param array $metadata Raw Server B response metadata.
     * @return void
     */
    public function bind_server_session(
        int $sessionid,
        string $serversessionid,
        ?string $serverroomid,
        array $metadata
    ): void {
        global $DB;

        if ($serversessionid === '') {
            throw new \coding_exception('Server session id cannot be empty.');
        }

        $duplicate = $DB->get_record(self::TABLE, ['server_sessionid' => $serversessionid], 'id');
        if ($duplicate && (int) $duplicate->id !== $sessionid) {
            throw new \moodle_exception('error:duplicateserversession', 'local_proctorcore');
        }

        $DB->update_record(self::TABLE, (object) [
            'id' => $sessionid,
            'server_sessionid' => $serversessionid,
            'server_roomid' => $serverroomid,
            'servermetadata' => $this->encode_metadata($metadata),
            'timemodified' => time(),
        ]);
    }

    /**
     * Updates technical and identity check statuses and merges check metadata.
     *
     * @param int $sessionid Local session id.
     * @param string $techcheckstatus pending, passed or failed.
     * @param string $identitystatus pending, passed, failed or notrequired.
     * @param array $metadatapatch Optional recursive metadata patch.
     * @return void
     */
    public function update_check_statuses(
        int $sessionid,
        string $techcheckstatus,
        string $identitystatus,
        array $metadatapatch = []
    ): void {
        global $DB;

        $allowedtech = ['pending', 'passed', 'failed'];
        $allowedidentity = ['pending', 'passed', 'failed', 'notrequired'];
        if (!in_array($techcheckstatus, $allowedtech, true)) {
            throw new \coding_exception('Invalid technical-check status: ' . $techcheckstatus);
        }
        if (!in_array($identitystatus, $allowedidentity, true)) {
            throw new \coding_exception('Invalid identity status: ' . $identitystatus);
        }

        $session = $this->get_by_id($sessionid);
        $update = (object) [
            'id' => $sessionid,
            'techcheckstatus' => $techcheckstatus,
            'identitystatus' => $identitystatus,
            'timemodified' => time(),
        ];
        if ($metadatapatch) {
            $metadata = array_replace_recursive(
                $this->decode_metadata($session->servermetadata),
                $metadatapatch
            );
            $update->servermetadata = $this->encode_metadata($metadata);
        }
        $DB->update_record(self::TABLE, $update);
    }

    /**
     * Updates lifecycle status and timestamps.
     *
     * @param int $sessionid Local session id.
     * @param string $status New status.
     * @return void
     */
    public function update_status(int $sessionid, string $status): void {
        global $DB;

        $allowed = ['created', 'precheck', 'active', 'interrupted', 'completed', 'failed', 'abandoned', 'expired'];
        if (!in_array($status, $allowed, true)) {
            throw new \coding_exception('Invalid ProctorCore session status: ' . $status);
        }

        $session = $this->get_by_id($sessionid);
        $now = time();
        $update = (object) [
            'id' => $sessionid,
            'status' => $status,
            'timemodified' => $now,
        ];
        if ($status === 'active' && empty($session->startedat)) {
            $update->startedat = $now;
            $update->lastheartbeat = $now;
        }
        if (in_array($status, ['completed', 'failed', 'abandoned', 'expired'], true) && empty($session->endedat)) {
            $update->endedat = $now;
        }
        $DB->update_record(self::TABLE, $update);
    }

    /**
     * Updates heartbeat time.
     *
     * @param int $sessionid Local session id.
     * @param int|null $timestamp Unix timestamp.
     * @return void
     */
    public function update_heartbeat(int $sessionid, ?int $timestamp = null): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $sessionid,
            'lastheartbeat' => $timestamp ?? time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Increments the number of snapshot-like assets received for a session.
     *
     * @param int $sessionid Local session id.
     * @return void
     */
    public function increment_snapshot_count(int $sessionid): void {
        global $DB;
        $DB->execute(
            "UPDATE {" . self::TABLE . "}
                SET snapshotcount = snapshotcount + 1,
                    timemodified = :now
              WHERE id = :id",
            ['now' => time(), 'id' => $sessionid]
        );
    }

    /**
     * Returns active sessions with stale heartbeat plus all interrupted sessions.
     *
     * @param int $stalebefore Heartbeat threshold.
     * @param int $limit Maximum records.
     * @return \stdClass[]
     */
    public function get_recovery_candidates(int $stalebefore, int $limit = 200): array {
        global $DB;

        $sql = "SELECT *
                  FROM {" . self::TABLE . "}
                 WHERE status = :interrupted
                    OR (
                        status = :active
                        AND COALESCE(lastheartbeat, startedat, timecreated) <= :stalebefore
                    )
              ORDER BY id ASC";

        return $DB->get_records_sql($sql, [
            'interrupted' => 'interrupted',
            'active' => 'active',
            'stalebefore' => $stalebefore,
        ], 0, max(1, $limit));
    }

    /**
     * Marks an active session interrupted without ending the Moodle quiz attempt.
     *
     * @param int $sessionid Local session id.
     * @param int $interruptedat Approximate interruption time.
     * @param int $reconnectdeadline Final allowed reconnect time.
     * @param string $reason Machine-readable reason.
     * @return \stdClass Updated session.
     */
    public function mark_interrupted(
        int $sessionid,
        int $interruptedat,
        int $reconnectdeadline,
        string $reason = 'heartbeat_timeout'
    ): \stdClass {
        global $DB;

        $session = $this->get_by_id($sessionid);
        if (!in_array($session->status, ['active', 'interrupted'], true)) {
            throw new \moodle_exception('error:invalidrecoverytransition', 'local_proctorcore');
        }

        $metadata = $this->decode_metadata($session->servermetadata);
        $previous = $metadata['connectionRecovery'] ?? [];
        $metadata['connectionRecovery'] = array_merge($previous, [
            'interruptedAt' => max(1, $interruptedat),
            'reconnectDeadline' => max($interruptedat, $reconnectdeadline),
            'reason' => clean_param($reason, PARAM_ALPHANUMEXT),
            'resumedAt' => null,
            'expiredAt' => null,
            'resumeCount' => (int) ($previous['resumeCount'] ?? 0),
        ]);

        $DB->update_record(self::TABLE, (object) [
            'id' => $sessionid,
            'status' => 'interrupted',
            'servermetadata' => $this->encode_metadata($metadata),
            'timemodified' => time(),
        ]);

        return $this->get_by_id($sessionid);
    }

    /**
     * Resumes an interrupted session using the same Moodle quiz attempt.
     *
     * @param int $sessionid Local session id.
     * @param int $resumedat Resume time.
     * @return \stdClass Updated session.
     */
    public function resume_interrupted(int $sessionid, int $resumedat): \stdClass {
        global $DB;

        $session = $this->get_by_id($sessionid);
        if ($session->status === 'active') {
            return $session;
        }
        if ($session->status !== 'interrupted') {
            throw new \moodle_exception('error:invalidrecoverytransition', 'local_proctorcore');
        }

        $metadata = $this->decode_metadata($session->servermetadata);
        $recovery = $metadata['connectionRecovery'] ?? [];
        $recovery['resumedAt'] = max(1, $resumedat);
        $recovery['resumeCount'] = (int) ($recovery['resumeCount'] ?? 0) + 1;
        $recovery['expiredAt'] = null;
        $metadata['connectionRecovery'] = $recovery;

        $DB->update_record(self::TABLE, (object) [
            'id' => $sessionid,
            'status' => 'active',
            'lastheartbeat' => max(1, $resumedat),
            'servermetadata' => $this->encode_metadata($metadata),
            'timemodified' => time(),
        ]);

        return $this->get_by_id($sessionid);
    }

    /**
     * Closes an interrupted session whose reconnect window expired.
     *
     * Quiz answers are not deleted or replaced. The original Moodle attempt remains.
     *
     * @param int $sessionid Local session id.
     * @param int $expiredat Expiry time.
     * @return \stdClass Updated session.
     */
    public function mark_reconnect_expired(int $sessionid, int $expiredat): \stdClass {
        global $DB;

        $session = $this->get_by_id($sessionid);
        if (in_array($session->status, ['abandoned', 'failed', 'completed', 'expired'], true)) {
            return $session;
        }
        if ($session->status !== 'interrupted') {
            throw new \moodle_exception('error:invalidrecoverytransition', 'local_proctorcore');
        }

        $metadata = $this->decode_metadata($session->servermetadata);
        $recovery = $metadata['connectionRecovery'] ?? [];
        $recovery['expiredAt'] = max(1, $expiredat);
        $metadata['connectionRecovery'] = $recovery;

        $DB->update_record(self::TABLE, (object) [
            'id' => $sessionid,
            'status' => 'abandoned',
            'endedat' => max(1, $expiredat),
            'closedreason' => 'reconnect_window_expired',
            'servermetadata' => $this->encode_metadata($metadata),
            'timemodified' => time(),
        ]);

        return $this->get_by_id($sessionid);
    }

    /**
     * Returns connection-recovery metadata.
     *
     * @param \stdClass $session Session record.
     * @return array
     */
    public function get_connection_recovery(\stdClass $session): array {
        $metadata = $this->decode_metadata($session->servermetadata);
        return is_array($metadata['connectionRecovery'] ?? null)
            ? $metadata['connectionRecovery']
            : [];
    }

    /**
     * Merges metadata without replacing unrelated Server B information.
     *
     * @param int $sessionid Local session id.
     * @param array $patch Recursive metadata patch.
     * @return \stdClass Updated session.
     */
    public function merge_server_metadata(int $sessionid, array $patch): \stdClass {
        global $DB;

        $session = $this->get_by_id($sessionid);
        $metadata = array_replace_recursive($this->decode_metadata($session->servermetadata), $patch);
        $DB->update_record(self::TABLE, (object) [
            'id' => $sessionid,
            'servermetadata' => $this->encode_metadata($metadata),
            'timemodified' => time(),
        ]);

        return $this->get_by_id($sessionid);
    }

    /**
     * Applies the final Server B proctoring result to the official Moodle record.
     *
     * @param int $sessionid Local session id.
     * @param string $result passed or failed.
     * @param string $status completed or failed.
     * @param string $closedreason Short reason.
     * @param int $completedat Completion time.
     * @param int $appealuntil Appeal deadline.
     * @param int $reportexpiresat Report expiry.
     * @param int $videoexpiresat Video expiry.
     * @param array $metadata Full event metadata.
     * @return \stdClass Updated session.
     */
    public function apply_final_result(
        int $sessionid,
        string $result,
        string $status,
        string $closedreason,
        int $completedat,
        int $appealuntil,
        int $reportexpiresat,
        int $videoexpiresat,
        array $metadata
    ): \stdClass {
        global $DB;

        if (!in_array($result, ['passed', 'failed'], true)) {
            throw new \moodle_exception('error:invalidresult', 'local_proctorcore');
        }
        if (!in_array($status, ['completed', 'failed'], true)) {
            throw new \coding_exception('Invalid final ProctorCore status: ' . $status);
        }

        $session = $this->get_by_id($sessionid);
        if ($session->result !== 'unknown' && $session->result !== $result) {
            throw new \moodle_exception('error:resultconflict', 'local_proctorcore');
        }

        $reason = trim(clean_param($closedreason, PARAM_TEXT));
        $reason = \core_text::substr($reason, 0, 64);

        $mergedmetadata = array_replace_recursive(
            $this->decode_metadata($session->servermetadata),
            ['finalWebhook' => $metadata]
        );

        $DB->update_record(self::TABLE, (object) [
            'id' => $sessionid,
            'status' => $status,
            'result' => $result,
            'endedat' => max(1, $completedat),
            'appealuntil' => max($completedat, $appealuntil),
            'reportexpiresat' => max($completedat, $reportexpiresat),
            'videoexpiresat' => max($completedat, $videoexpiresat),
            'closedreason' => $reason !== '' ? $reason : null,
            'servermetadata' => $this->encode_metadata($mergedmetadata),
            'timemodified' => time(),
        ]);

        return $this->get_by_id($sessionid);
    }

    /**
     * Decodes optional JSON metadata safely.
     *
     * @param string|null $json JSON text.
     * @return array
     */
    private function decode_metadata(?string $json): array {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Encodes metadata.
     *
     * @param array $metadata Metadata.
     * @return string
     */
    private function encode_metadata(array $metadata): string {
        $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \coding_exception('Unable to encode ProctorCore session metadata.');
        }
        return $encoded;
    }
>>>>>>> origin/danial
}
