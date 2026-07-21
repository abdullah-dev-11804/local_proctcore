<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Coordinates Moodle/IOMAD session creation with Server B.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class integration_service {
    /** @var tenant_resolver */
    private $tenantresolver;

    /** @var session_repository */
    private $sessions;

    /**
     * Constructor.
     *
     * @param tenant_resolver|null $tenantresolver Optional dependency.
     * @param session_repository|null $sessions Optional dependency.
     */
    public function __construct(
        ?tenant_resolver $tenantresolver = null,
        ?session_repository $sessions = null
    ) {
        $this->tenantresolver = $tenantresolver ?? new tenant_resolver();
        $this->sessions = $sessions ?? new session_repository();
    }

    /**
     * Creates and binds a Server B session for a Moodle quiz attempt.
     *
     * This operation is idempotent. Repeating it for the same tenant/attempt
     * returns the existing local session and does not create a second Server B
     * session after the external id has already been stored.
     *
     * @param int $attemptid Moodle quiz attempt id.
     * @return \stdClass Updated local session record.
     */
    public function create_session_for_attempt(int $attemptid): \stdClass {
        global $CFG, $DB;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $quiz->course], 'id,fullname,shortname', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $user = $DB->get_record('user', ['id' => $attempt->userid, 'deleted' => 0],
            'id,username,firstname,lastname,email,lang', MUST_EXIST);

        $companyid = $this->tenantresolver->resolve_company_id((int) $user->id, (int) $course->id);
        if (!$this->tenantresolver->is_course_available_to_company((int) $course->id, $companyid)) {
            throw new \moodle_exception('error:coursecompanymismatch', 'local_proctorcore');
        }

        $session = $this->sessions->create_or_get([
            'companyid' => $companyid,
            'courseid' => $course->id,
            'cmid' => $cm->id,
            'quizid' => $quiz->id,
            'attemptid' => $attempt->id,
            'userid' => $user->id,
        ]);

        // Development-only local mode keeps the production Server B code in
        // place but binds a synthetic external id so the normal Moodle session
        // lifecycle can continue without an external API.
        if (local_capture_storage::is_enabled()) {
            if (empty($session->server_sessionid)) {
                $this->sessions->bind_server_session(
                    (int) $session->id,
                    'local-session-' . (int) $session->id,
                    'local-room-' . (int) $session->id,
                    [
                        'mode' => 'localtest',
                        'storagePath' => local_capture_storage::get_base_path(true),
                        'createdAt' => gmdate('c'),
                    ]
                );
            }
            return $this->sessions->get_by_id((int) $session->id);
        }

        if (!empty($session->server_sessionid)) {
            return $session;
        }

        // Multiple Moodle hooks can reach this method during the same attempt
        // (preflight notification, access enforcement, page setup). Serialise
        // Server B creation so an incomplete local row can be repaired safely
        // without creating duplicate external sessions.
        $factory = \core\lock\lock_config::get_lock_factory('local_proctorcore');
        $lock = $factory->get_lock('create_server_session_' . (int) $session->id, 20);
        if (!$lock) {
            throw new \moodle_exception('error:sessioncreationbusy', 'local_proctorcore');
        }

        try {
            $session = $this->sessions->get_by_id((int) $session->id);
            if (!empty($session->server_sessionid)) {
                return $session;
            }

            $client = new server_client($companyid);
            $payload = [
            'moodleSessionId' => (int) $session->id,
            'companyId' => $companyid,
            'courseId' => (int) $course->id,
            'courseName' => format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]),
            'cmid' => (int) $cm->id,
            'quizId' => (int) $quiz->id,
            'quizName' => format_string($quiz->name, true, ['context' => \context_module::instance($cm->id)]),
            'attemptId' => (int) $attempt->id,
            'attemptNumber' => (int) $attempt->attempt,
            'timer' => [
                'source' => 'moodle',
                'attemptStartedAt' => (int) $attempt->timestart,
                'timeLimitSeconds' => (int) $quiz->timelimit,
                'quizCloseAt' => (int) $quiz->timeclose,
                'timerReset' => false,
            ],
            'user' => [
                'id' => (int) $user->id,
                'username' => (string) $user->username,
                'fullName' => fullname($user),
                'email' => (string) $user->email,
                'language' => (string) $user->lang,
            ],
            'callbackUrl' => (new \moodle_url('/local/proctorcore/webhook.php'))->out(false),
            'returnUrl' => (new \moodle_url('/mod/quiz/attempt.php', ['attempt' => $attempt->id]))->out(false),
            'source' => [
                'platform' => 'Moodle',
                'moodleVersion' => (string) $CFG->release,
                'plugin' => 'local_proctorcore',
            ],
        ];

            $response = $client->create_session($payload);
            $sessiondata = $response['session'] ?? $response;
            $serversessionid = (string) ($sessiondata['sessionId'] ?? $sessiondata['id'] ?? '');
            if ($serversessionid === '') {
                throw new \moodle_exception('error:serversessionmissing', 'local_proctorcore');
            }

            $this->sessions->bind_server_session(
                (int) $session->id,
                $serversessionid,
                isset($sessiondata['roomId']) ? (string) $sessiondata['roomId'] : null,
                $response
            );

            return $this->sessions->get_by_id((int) $session->id);
        } finally {
            $lock->release();
        }
    }

    /**
     * Checks Server B health for a company.
     *
     * @param int $companyid Company id.
     * @return array
     */
    public function health(int $companyid): array {
        if (local_capture_storage::is_enabled()) {
            return local_capture_storage::health();
        }
        return (new server_client($companyid))->health();
    }
}
