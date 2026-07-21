<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Section 5.3 connection-loss and resume service.
 *
 * Moodle's original quiz attempt remains authoritative for answers and time.
 * This service never creates a second quiz attempt and never resets timestart.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connection_recovery_service {
    /** @var session_repository */
    private $sessions;

    /** @var audit_logger */
    private $audit;

    /**
     * Constructor.
     *
     * @param session_repository|null $sessions Session repository.
     * @param audit_logger|null $audit Audit logger.
     */
    public function __construct(
        ?session_repository $sessions = null,
        ?audit_logger $audit = null
    ) {
        $this->sessions = $sessions ?? new session_repository();
        $this->audit = $audit ?? new audit_logger();
    }

    /**
     * Records one browser heartbeat.
     *
     * @param int $sessionid Local ProctorCore session id.
     * @param int $userid Current Moodle user id.
     * @return array Status information for the frontend.
     */
    public function heartbeat(int $sessionid, int $userid): array {
        $session = $this->sessions->get_by_id($sessionid);
        $this->require_owner($session, $userid);

        if (in_array($session->status, ['completed', 'failed', 'abandoned', 'expired'], true)) {
            throw new \moodle_exception('error:recoverysessionclosed', 'local_proctorcore');
        }

        if ($session->status === 'interrupted') {
            $recovery = $this->sessions->get_connection_recovery($session);
            return [
                'ok' => true,
                'status' => 'interrupted',
                'sessionId' => (int) $session->id,
                'serverSessionId' => (string) $session->server_sessionid,
                'reconnectDeadline' => (int) ($recovery['reconnectDeadline'] ?? 0),
                'reconnectUrl' => $this->get_reconnect_url((int) $session->attemptid),
            ];
        }

        if ($session->status !== 'active') {
            throw new \moodle_exception('error:recoveryrequiresactive', 'local_proctorcore');
        }

        $now = time();

        // Record the browser heartbeat locally first. A temporary Server B
        // outage must not make a connected browser look disconnected to Moodle.
        $this->sessions->update_heartbeat((int) $session->id, $now);

        $serversynced = true;
        $serversyncerror = null;
        if (!local_capture_storage::is_enabled() && !empty($session->server_sessionid)) {
            try {
                $client = new server_client((int) $session->companyid);
                $client->heartbeat((string) $session->server_sessionid, [
                    'moodleSessionId' => (int) $session->id,
                    'attemptId' => (int) $session->attemptid,
                    'userId' => (int) $session->userid,
                    'companyId' => (int) $session->companyid,
                    'heartbeatAt' => gmdate('c', $now),
                    'status' => 'active',
                ]);
            } catch (\Throwable $exception) {
                $serversynced = false;
                $serversyncerror = clean_param($exception->getMessage(), PARAM_TEXT);
                $this->record_server_sync_error($session, 'heartbeat', $exception);
            }
        }

        return [
            'ok' => true,
            'status' => 'active',
            'sessionId' => (int) $session->id,
            'serverSessionId' => (string) $session->server_sessionid,
            'heartbeatAt' => $now,
            'serverSynced' => $serversynced,
            'serverSyncError' => $serversyncerror,
        ];
    }

    /**
     * Resumes the original Moodle quiz attempt inside the allowed window.
     *
     * @param int $attemptid Moodle quiz attempt id.
     * @param int $userid Current Moodle user id.
     * @return array Resume information and original attempt URL.
     */
    public function resume_attempt(int $attemptid, int $userid): array {
        global $DB;

        $session = $this->sessions->get_by_attempt_and_user($attemptid, $userid);
        if (!$session) {
            throw new \moodle_exception('error:recoverysessionnotfound', 'local_proctorcore');
        }

        $attempt = $DB->get_record('quiz_attempts', [
            'id' => $attemptid,
            'userid' => $userid,
        ], '*', MUST_EXIST);

        if ((string) $attempt->state !== 'inprogress') {
            throw new \moodle_exception('error:quizattemptnotinprogress', 'local_proctorcore');
        }

        if ($session->status === 'active') {
            return $this->build_resume_response($session, $attempt, false);
        }

        if ($session->status !== 'interrupted') {
            throw new \moodle_exception('error:recoverysessionclosed', 'local_proctorcore');
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_proctorcore_connection_recovery');
        $lock = $lockfactory->get_lock('session_' . (int) $session->id, 10);
        if (!$lock) {
            throw new \moodle_exception('error:recoverybusy', 'local_proctorcore');
        }

        try {
            $session = $this->sessions->get_by_id((int) $session->id);
            if ($session->status === 'active') {
                return $this->build_resume_response($session, $attempt, false);
            }

            $recovery = $this->sessions->get_connection_recovery($session);
            $deadline = (int) ($recovery['reconnectDeadline'] ?? 0);
            if ($deadline <= 0) {
                $interruptedat = (int) ($session->lastheartbeat ?: $session->timemodified ?: time());
                $deadline = $interruptedat + $this->get_resume_window_seconds($session);
                $session = $this->sessions->mark_interrupted(
                    (int) $session->id,
                    $interruptedat,
                    $deadline,
                    'recovery_metadata_rebuilt'
                );
            }

            $now = time();
            if ($now > $deadline) {
                $this->expire_session($session, $now);
                throw new \moodle_exception('error:reconnectwindowexpired', 'local_proctorcore');
            }

            if (!local_capture_storage::is_enabled() && !empty($session->server_sessionid)) {
                $client = new server_client((int) $session->companyid);
                $client->resume_session((string) $session->server_sessionid, [
                    'moodleSessionId' => (int) $session->id,
                    'attemptId' => (int) $session->attemptid,
                    'userId' => (int) $session->userid,
                    'companyId' => (int) $session->companyid,
                    'resumedAt' => gmdate('c', $now),
                    'timer' => $this->get_timer_reference($attempt),
                ]);
            }

            $session = $this->sessions->resume_interrupted((int) $session->id, $now);
            $this->audit->log(
                'session.connection_resumed',
                (int) $session->companyid,
                (int) $session->id,
                (int) $session->userid,
                [
                    'attemptId' => (int) $session->attemptid,
                    'serverSessionId' => (string) $session->server_sessionid,
                    'resumedAt' => $now,
                    'reconnectDeadline' => $deadline,
                    'sameAttempt' => true,
                    'timerReset' => false,
                ],
                $userid,
                'session',
                (int) $session->id
            );

            return $this->build_resume_response($session, $attempt, true);
        } finally {
            $lock->release();
        }
    }

    /**
     * Detects stale sessions and expires missed reconnect windows.
     *
     * @param int $limit Maximum sessions per run.
     * @return array Counts of changed records.
     */
    public function process_timeouts(int $limit = 200): array {
        $now = time();
        $grace = $this->get_heartbeat_grace_seconds();
        $candidates = $this->sessions->get_recovery_candidates($now - $grace, $limit);

        $counts = [
            'checked' => 0,
            'interrupted' => 0,
            'expired' => 0,
            'errors' => 0,
        ];

        foreach ($candidates as $candidate) {
            $counts['checked']++;
            try {
                $lockfactory = \core\lock\lock_config::get_lock_factory('local_proctorcore_connection_recovery');
                $lock = $lockfactory->get_lock('session_' . (int) $candidate->id, 1);
                if (!$lock) {
                    continue;
                }

                try {
                    $session = $this->sessions->get_by_id((int) $candidate->id);
                    if ($session->status === 'active') {
                        $heartbeat = (int) ($session->lastheartbeat ?: $session->startedat ?: $session->timecreated);
                        if ($heartbeat > $now - $grace) {
                            continue;
                        }

                        $interruptedat = max(1, $heartbeat);
                        $deadline = $interruptedat + $this->get_resume_window_seconds($session);
                        $session = $this->sessions->mark_interrupted(
                            (int) $session->id,
                            $interruptedat,
                            $deadline,
                            'heartbeat_timeout'
                        );
                        $counts['interrupted']++;

                        $this->audit->log(
                            'session.connection_interrupted',
                            (int) $session->companyid,
                            (int) $session->id,
                            (int) $session->userid,
                            [
                                'attemptId' => (int) $session->attemptid,
                                'serverSessionId' => (string) $session->server_sessionid,
                                'lastHeartbeat' => $heartbeat,
                                'interruptedAt' => $interruptedat,
                                'reconnectDeadline' => $deadline,
                            ],
                            null,
                            'session',
                            (int) $session->id
                        );

                        $this->notify_server_interrupted($session, $interruptedat, $deadline);
                    }

                    if ($session->status === 'interrupted') {
                        $recovery = $this->sessions->get_connection_recovery($session);
                        $deadline = (int) ($recovery['reconnectDeadline'] ?? 0);
                        if ($deadline > 0 && $now > $deadline) {
                            $this->expire_session($session, $now);
                            $counts['expired']++;
                        }
                    }
                } finally {
                    $lock->release();
                }
            } catch (\Throwable $exception) {
                $counts['errors']++;
                debugging('ProctorCore recovery task error for session ' . (int) $candidate->id
                    . ': ' . $exception->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return $counts;
    }

    /**
     * Returns the effective reconnect window for a session.
     *
     * @param \stdClass $session Session record.
     * @return int Seconds.
     */
    public function get_resume_window_seconds(\stdClass $session): int {
        global $DB;

        $quizcfg = $DB->get_record('local_proctorcore_quizcfg', [
            'companyid' => (int) $session->companyid,
            'quizid' => (int) $session->quizid,
        ], 'allowresume,resumewindowsecs');

        // IOMAD company-specific configuration falls back to company 0.
        if (!$quizcfg && (int) $session->companyid !== 0) {
            $quizcfg = $DB->get_record('local_proctorcore_quizcfg', [
                'companyid' => 0,
                'quizid' => (int) $session->quizid,
            ], 'allowresume,resumewindowsecs');
        }

        if ($quizcfg) {
            if (empty($quizcfg->allowresume)) {
                return 0;
            }
            return min(3600, max(60, (int) $quizcfg->resumewindowsecs));
        }

        $configured = (int) get_config('local_proctorcore', 'defaultresumewindowsecs');
        return min(3600, max(60, $configured > 0 ? $configured : 600));
    }

    /**
     * Returns heartbeat timeout threshold.
     *
     * @return int Seconds.
     */
    public function get_heartbeat_grace_seconds(): int {
        $configured = (int) get_config('local_proctorcore', 'heartbeatgracesecs');
        return min(300, max(30, $configured > 0 ? $configured : 45));
    }

    /**
     * Builds URL that explicitly resumes the original attempt.
     *
     * @param int $attemptid Quiz attempt id.
     * @return string
     */
    public function get_reconnect_url(int $attemptid): string {
        return (new \moodle_url('/local/proctorcore/reconnect.php', [
            'attemptid' => $attemptid,
            'sesskey' => sesskey(),
        ]))->out(false);
    }

    /**
     * Expires an interrupted session locally and asks Server B to fail it.
     *
     * @param \stdClass $session Session record.
     * @param int $now Expiry time.
     * @return void
     */
    private function expire_session(\stdClass $session, int $now): void {
        $session = $this->sessions->mark_reconnect_expired((int) $session->id, $now);

        $this->audit->log(
            'session.reconnect_expired',
            (int) $session->companyid,
            (int) $session->id,
            (int) $session->userid,
            [
                'attemptId' => (int) $session->attemptid,
                'serverSessionId' => (string) $session->server_sessionid,
                'expiredAt' => $now,
                'quizAttemptPreserved' => true,
            ],
            null,
            'session',
            (int) $session->id
        );

        if (empty($session->server_sessionid) || local_capture_storage::is_enabled()) {
            return;
        }

        try {
            $client = new server_client((int) $session->companyid);
            $client->fail_session((string) $session->server_sessionid, [
                'moodleSessionId' => (int) $session->id,
                'attemptId' => (int) $session->attemptid,
                'companyId' => (int) $session->companyid,
                'reasonCode' => 'reconnect_window_expired',
                'reason' => 'The test-taker did not reconnect within the allowed window.',
                'failedAt' => gmdate('c', $now),
            ]);
        } catch (\Throwable $exception) {
            $this->record_server_sync_error($session, 'fail', $exception);
        }
    }

    /**
     * Notifies Server B about an interruption.
     *
     * @param \stdClass $session Session record.
     * @param int $interruptedat Interruption time.
     * @param int $deadline Reconnect deadline.
     * @return void
     */
    private function notify_server_interrupted(\stdClass $session, int $interruptedat, int $deadline): void {
        if (empty($session->server_sessionid)) {
            return;
        }

        try {
            // Section 1.1: finalise and preserve the current partial recording
            // before the session enters its reconnect window.
            try {
                (new capture_service($this->sessions))->stop_capture(
                    (int) $session->id,
                    null,
                    'connection_lost'
                );
            } catch (\Throwable $captureexception) {
                $this->record_server_sync_error($session, 'recording_stop', $captureexception);
            }

            if (local_capture_storage::is_enabled()) {
                return;
            }

            $client = new server_client((int) $session->companyid);
            $client->interrupt_session((string) $session->server_sessionid, [
                'moodleSessionId' => (int) $session->id,
                'attemptId' => (int) $session->attemptid,
                'companyId' => (int) $session->companyid,
                'interruptedAt' => gmdate('c', $interruptedat),
                'reconnectDeadline' => gmdate('c', $deadline),
                'reasonCode' => 'heartbeat_timeout',
            ]);
        } catch (\Throwable $exception) {
            $this->record_server_sync_error($session, 'interrupt', $exception);
        }
    }

    /**
     * Records a non-fatal Server B synchronization error.
     *
     * @param \stdClass $session Session record.
     * @param string $operation Operation name.
     * @param \Throwable $exception Error.
     * @return void
     */
    private function record_server_sync_error(\stdClass $session, string $operation, \Throwable $exception): void {
        $this->sessions->merge_server_metadata((int) $session->id, [
            'connectionRecovery' => [
                'lastServerSyncError' => [
                    'operation' => $operation,
                    'message' => clean_param($exception->getMessage(), PARAM_TEXT),
                    'time' => time(),
                ],
            ],
        ]);
        debugging('ProctorCore Server B recovery sync failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
    }

    /**
     * Requires that a session belongs to the current user.
     *
     * @param \stdClass $session Session record.
     * @param int $userid Current user id.
     * @return void
     */
    private function require_owner(\stdClass $session, int $userid): void {
        if ((int) $session->userid !== $userid) {
            throw new \moodle_exception('error:sessionowner', 'local_proctorcore');
        }
    }

    /**
     * Returns the original Moodle timer reference.
     *
     * Moodle remains authoritative. Reconnect does not write to quiz_attempts.timestart.
     *
     * @param \stdClass $attempt Quiz attempt record.
     * @return array
     */
    private function get_timer_reference(\stdClass $attempt): array {
        global $DB;
        $quiz = $DB->get_record('quiz', ['id' => (int) $attempt->quiz], 'id,timelimit,timeclose', MUST_EXIST);

        return [
            'source' => 'moodle',
            'attemptId' => (int) $attempt->id,
            'attemptStartedAt' => (int) $attempt->timestart,
            'timeLimitSeconds' => (int) $quiz->timelimit,
            'quizCloseAt' => (int) $quiz->timeclose,
            'timerReset' => false,
        ];
    }

    /**
     * Builds the resume response.
     *
     * @param \stdClass $session Session record.
     * @param \stdClass $attempt Quiz attempt record.
     * @param bool $resumed Whether a transition occurred.
     * @return array
     */
    private function build_resume_response(\stdClass $session, \stdClass $attempt, bool $resumed): array {
        return [
            'ok' => true,
            'resumed' => $resumed,
            'status' => (string) $session->status,
            'sessionId' => (int) $session->id,
            'serverSessionId' => (string) $session->server_sessionid,
            'attemptId' => (int) $attempt->id,
            'attemptUrl' => (new \moodle_url('/mod/quiz/attempt.php', [
                'attempt' => (int) $attempt->id,
                'cmid' => (int) $session->cmid,
            ]))->out(false),
            'sameAttempt' => true,
            'answersPreserved' => true,
            'timerReset' => false,
            'timer' => $this->get_timer_reference($attempt),
        ];
    }
}
