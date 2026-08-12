<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Section 1.2 identity verification service.
 *
 * Pre-attempt identity results live in the authenticated Moodle session until
 * Moodle creates the real Quiz attempt. The reusable biometric reference image
 * is stored only on Server B; Moodle stores confirmation and audit metadata.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class identity_service {
    /** Result lifetime, seconds. */
    private const RESULT_TTL = 900;

    /**
     * Enrolls or verifies the user against the Server B face reference.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @param string $token Current preflight token.
     * @param string|array $centerdata Data URL/base64 centre frame(s).
     * @param string|array $leftdata Data URL/base64 left-turn frame(s).
     * @param string|array $rightdata Data URL/base64 right-turn frame(s).
     * @param string $confirmedname Name confirmed by the user during first enrollment.
     * @param bool $confirmedenrollment Whether the user explicitly confirmed enrollment.
     * @return array Public response.
     */
    public function verify_preflight(
        int $quizid,
        int $userid,
        string $token,
        $centerdata,
        $leftdata,
        $rightdata,
        string $confirmedname = '',
        bool $confirmedenrollment = false
    ): array {
        global $DB;

        $this->require_precheck_token($quizid, $userid, $token);
        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id,course', MUST_EXIST);
        $companyid = (new tenant_resolver())->resolve_company_id($userid, (int) $quiz->course);
        $config = (new company_config_repository())->get_effective_config($companyid);
        if (empty($config->identityenabled)) {
            $result = [
                'passed' => true,
                'status' => 'notrequired',
                'score' => null,
                'threshold' => (float) $config->identitythreshold,
                'livenessPassed' => true,
                'mode' => 'notrequired',
                'checkedAt' => time(),
                'transactionId' => 'identity-disabled-' . $quizid . '-' . $userid,
            ];
            $this->remember($quizid, $userid, $result);
            return $this->public_result($result);
        }

        $centerframes = $this->decode_images($centerdata, 12);
        $leftframes = $this->decode_images($leftdata, 16);
        $rightframes = $this->decode_images($rightdata, 16);
        $transactionid = bin2hex(random_bytes(16));
        $enrollments = new face_enrollment_repository();
        $enrollment = $enrollments->get_active($userid);
        $server = new server_client($companyid);
        $fullname = fullname(\core_user::get_user($userid, '*', MUST_EXIST));
        $mismatchmode = (string) ($config->identitymismatchmode ?? 'review');

        if (!$enrollment) {
            if (!$confirmedenrollment) {
                throw new \moodle_exception('identity:confirmationrequired', 'local_proctorcore');
            }
            $confirmedname = $fullname;
            $response = $server->enroll_face_reference(
                $userid,
                $centerframes,
                $leftframes,
                $rightframes,
                $transactionid,
                $fullname,
                time(),
                (float) $config->identitythreshold
            );
            $status = clean_param((string) ($response['result'] ?? 'enrollment_error'), PARAM_ALPHANUMEXT);
            $passed = $status === 'enrolled';
            $result = $this->normalise_server_result(
                $response,
                $passed,
                $passed ? 'enrolled' : $status,
                'enroll',
                $transactionid,
                (float) $config->identitythreshold,
                $mismatchmode
            );
            $result['confirmedName'] = $confirmedname;
            $result['confirmedAt'] = time();
            if ($passed) {
                $enrollments->upsert_active(
                    $userid,
                    $companyid,
                    (string) ($response['referenceId'] ?? ''),
                    (string) ($response['referenceKey'] ?? ''),
                    $confirmedname,
                    (int) $result['confirmedAt'],
                    is_array($response['quality'] ?? null) ? $response['quality'] : [],
                    $response,
                    $userid
                );
            } else {
                try {
                    $server->reset_face_reference($userid, 'failed_first_enrollment_cleanup');
                } catch (\Throwable $exception) {
                    debugging('ProctorCore failed enrollment cleanup failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
                }
            }
        } else {
            $response = $server->verify_face_reference(
                $userid,
                $centerframes,
                $leftframes,
                $rightframes,
                $transactionid,
                (float) $config->identitythreshold
            );
            $rawstatus = clean_param((string) ($response['result'] ?? 'verification_error'), PARAM_ALPHANUMEXT);
            $matched = $rawstatus === 'matched' || !empty($response['accessAllowed']);
            $qualityretry = in_array($rawstatus, [
                'no_face',
                'low_light',
                'blurry',
                'multiple_faces',
                'identity_no_face',
                'identity_low_light',
                'identity_blurry',
                'identity_multiple_faces',
                'identity_liveness_failed',
                'identity_head_turn_not_detected',
                'identity_side_face_missing',
                'liveness_failed',
                'head_turn_not_detected',
                'side_face_missing',
                'low_face_confidence',
                'identity_low_face_confidence',
            ], true);
            $passed = $matched;
            $status = $matched ? 'matched' : $rawstatus;
            if (!$matched && !$qualityretry) {
                if ($mismatchmode === 'review') {
                    $passed = true;
                    $status = 'needs_review';
                } else if ($mismatchmode === 'fail') {
                    $passed = true;
                    $status = 'failed_allowed';
                }
            }
            $result = $this->normalise_server_result(
                $response,
                $passed,
                $status,
                'verify',
                $transactionid,
                (float) $config->identitythreshold,
                $mismatchmode
            );
            $result['referenceId'] = (string) $enrollment->server_referenceid;
        }

        $this->remember($quizid, $userid, $result);

        (new audit_logger())->log(
            !empty($result['passed']) ? 'identity.preflight_passed' : 'identity.preflight_failed',
            $companyid,
            null,
            $userid,
            [
                'quizId' => $quizid,
                'status' => $result['status'],
                'score' => $result['score'],
                'threshold' => $result['threshold'],
                'mode' => $result['mode'],
                'mismatchMode' => $mismatchmode,
                'transactionId' => $transactionid,
            ],
            $userid,
            'quiz',
            $quizid
        );

        return $this->public_result($result);
    }

    /**
     * Returns a valid remembered result.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @return array|null
     */
    public function get_preflight_result(int $quizid, int $userid): ?array {
        global $SESSION;
        $state = $SESSION->local_proctorcore_identity[$this->key($quizid, $userid)] ?? null;
        if (!is_array($state)
                || empty($state['result'])
                || !is_array($state['result'])
                || empty($state['rememberedAt'])
                || time() - (int) $state['rememberedAt'] > self::RESULT_TTL) {
            return null;
        }
        return $state['result'];
    }

    /**
     * Applies the preflight identity decision and protected live photo to a session.
     *
     * @param int $sessionid Local ProctorCore session id.
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @param bool $required Whether identity is required.
     * @return \stdClass Updated session.
     */
    public function apply_to_session(
        int $sessionid,
        int $quizid,
        int $userid,
        bool $required
    ): \stdClass {
        global $SESSION;

        $sessions = new session_repository();
        $session = $sessions->get_by_id($sessionid);
        if (!$required) {
            $sessions->update_check_statuses($sessionid, (string) $session->techcheckstatus, 'notrequired', [
                'identity' => ['status' => 'notrequired', 'checkedAt' => time()],
            ]);
            return $sessions->get_by_id($sessionid);
        }

        $key = $this->key($quizid, $userid);
        $result = $this->get_preflight_result($quizid, $userid);
        if (!$result || empty($result['passed'])) {
            $sessions->update_check_statuses($sessionid, (string) $session->techcheckstatus, 'failed', [
                'identity' => $result ?: ['status' => 'missing'],
            ]);
            $this->create_identity_violation($session, $result ?: ['status' => 'missing']);
            throw new \moodle_exception('identity:notpassed', 'local_proctorcore');
        }

        $sessions->update_check_statuses($sessionid, (string) $session->techcheckstatus, 'passed', [
            'identity' => $result,
        ]);

        if (($result['status'] ?? '') === 'failed_allowed') {
            $this->create_identity_violation($session, $result);
            $this->mark_session_failed_for_identity((int) $session->id);
        }

        (new audit_logger())->log(
            'identity.session_applied',
            (int) $session->companyid,
            $sessionid,
            $userid,
            [
                'status' => $result['status'],
                'score' => $result['score'],
                'threshold' => $result['threshold'],
                'mode' => $result['mode'] ?? 'verify',
                'mismatchMode' => $result['mismatchMode'] ?? null,
                'transactionId' => $result['transactionId'],
            ],
            $userid,
            'session',
            $sessionid
        );

        unset($SESSION->local_proctorcore_identity[$key]);
        return $sessions->get_by_id($sessionid);
    }

    /**
     * Resets a user's reusable face reference.
     *
     * @param int $userid User whose reference is reset.
     * @param string $reason Administrator supplied reason.
     * @param int $actoruserid Administrator user id.
     * @return void
     */
    public function reset_reference(int $userid, string $reason, int $actoruserid): void {
        $reason = trim(clean_param($reason, PARAM_TEXT));
        if ($reason === '') {
            throw new \moodle_exception('identity:resetreasonrequired', 'local_proctorcore');
        }

        $repository = new face_enrollment_repository();
        $enrollment = $repository->get_active($userid);
        $companyid = $enrollment ? (int) $enrollment->companyid : 0;

        $serverresponse = [];
        try {
            $serverresponse = (new server_client($companyid))->reset_face_reference($userid, $reason);
        } catch (\moodle_exception $exception) {
            if ($enrollment) {
                throw $exception;
            }
        }

        $repository->mark_reset($userid, $actoruserid, $reason);
        (new audit_logger())->log(
            'identity.reference_reset',
            $companyid,
            null,
            $userid,
            [
                'reason' => $reason,
                'serverResponse' => $serverresponse,
            ],
            $actoruserid,
            'user',
            $userid
        );
    }

    /**
     * Erases a user's face reference after account deletion or approved erasure.
     *
     * @param int $userid User id.
     * @param string $reason Erasure reason.
     * @param int|null $actoruserid Optional actor user id.
     * @return void
     */
    public function erase_reference(int $userid, string $reason, ?int $actoruserid = null): void {
        $repository = new face_enrollment_repository();
        $enrollment = $repository->get_active($userid);
        if (!$enrollment) {
            return;
        }

        $companyid = (int) $enrollment->companyid;
        $serverresponse = [];
        try {
            $serverresponse = (new server_client($companyid))->reset_face_reference($userid, $reason);
        } catch (\Throwable $exception) {
            debugging('ProctorCore face reference erasure failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
        }

        $repository->mark_deleted($userid, $actoruserid, $reason);
        (new audit_logger())->log(
            'identity.reference_erased',
            $companyid,
            null,
            $userid,
            [
                'reason' => $reason,
                'serverResponse' => $serverresponse,
            ],
            $actoruserid,
            'user',
            $userid
        );
    }

    /** @return array */
    private function public_result(array $result): array {
        $messagekey = 'identity:failed';
        if (!empty($result['passed'])) {
            $messagekey = ($result['status'] ?? '') === 'enrolled' ? 'identity:enrolled' : 'identity:passed';
            if (($result['status'] ?? '') === 'needs_review') {
                $messagekey = 'identity:needsreview';
            }
        }
        return [
            'ok' => true,
            'passed' => !empty($result['passed']),
            'result' => (string) $result['status'],
            'similarityScore' => $result['score'],
            'threshold' => $result['threshold'],
            'livenessPassed' => !empty($result['livenessPassed']),
            'enrollment' => ($result['mode'] ?? '') === 'enroll',
            'message' => get_string($messagekey, 'local_proctorcore'),
        ];
    }

    /**
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @param array $result Result.
     * @return void
     */
    private function remember(int $quizid, int $userid, array $result): void {
        global $SESSION;
        if (!isset($SESSION->local_proctorcore_identity)
                || !is_array($SESSION->local_proctorcore_identity)) {
            $SESSION->local_proctorcore_identity = [];
        }
        $SESSION->local_proctorcore_identity[$this->key($quizid, $userid)] = [
            'result' => $result,
            'rememberedAt' => time(),
        ];
    }

    /**
     * @param string $data Data URL/base64.
     * @return string
     */
    private function decode_image(string $data): string {
        $clean = trim($data);
        if (strpos($clean, ',') !== false) {
            [, $clean] = explode(',', $clean, 2);
        }
        $bytes = base64_decode($clean, true);
        if ($bytes === false || strlen($bytes) < 256 || strlen($bytes) > 8 * 1024 * 1024) {
            throw new \moodle_exception('identity:invalidimage', 'local_proctorcore');
        }
        return $bytes;
    }

    /**
     * Normalises Server B identity output into the Moodle preflight shape.
     *
     * @param array $response Server B response.
     * @param bool $passed Whether Moodle should allow the preflight to pass.
     * @param string $status Moodle-facing status.
     * @param string $mode enroll or verify.
     * @param string $transactionid Correlation id.
     * @param float $defaultthreshold Configured threshold.
     * @param string $mismatchmode Configured mismatch mode.
     * @return array
     */
    private function normalise_server_result(
        array $response,
        bool $passed,
        string $status,
        string $mode,
        string $transactionid,
        float $defaultthreshold,
        string $mismatchmode
    ): array {
        return [
            'passed' => $passed,
            'status' => clean_param($status, PARAM_ALPHANUMEXT),
            'score' => isset($response['similarityScore']) ? (float) $response['similarityScore'] : null,
            'threshold' => isset($response['threshold']) ? (float) $response['threshold'] : $defaultthreshold,
            'livenessPassed' => array_key_exists('livenessPassed', $response) ? !empty($response['livenessPassed']) : true,
            'referenceFaceCount' => (int) ($response['referenceFaceCount'] ?? 0),
            'liveFaceCount' => (int) ($response['liveFaceCount'] ?? 0),
            'quality' => $response['quality'] ?? null,
            'reason' => clean_param((string) ($response['reason'] ?? ($response['result'] ?? 'unknown')), PARAM_TEXT),
            'mode' => $mode,
            'mismatchMode' => $mismatchmode,
            'manualReviewRequired' => $status === 'needs_review',
            'checkedAt' => time(),
            'transactionId' => $transactionid,
        ];
    }

    /**
     * Decodes one image or a list of challenge frames.
     *
     * @param string|array $data Data URL/base64 image or list.
     * @param int $limit Maximum frames accepted.
     * @return array
     */
    private function decode_images($data, int $limit): array {
        $values = is_array($data) ? $data : [$data];
        $frames = [];
        foreach (array_slice($values, 0, $limit) as $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $frames[] = $this->decode_image($value);
        }
        if (!$frames) {
            throw new \moodle_exception('identity:invalidimage', 'local_proctorcore');
        }
        return $frames;
    }

    /**
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @param string $token Token.
     * @return void
     */
    private function require_precheck_token(int $quizid, int $userid, string $token): void {
        global $SESSION;
        $state = $SESSION->local_proctorcore_prechecks[$this->key($quizid, $userid)] ?? null;
        if (!is_array($state)
                || empty($state['token'])
                || $token === ''
                || !hash_equals((string) $state['token'], $token)) {
            throw new \moodle_exception('precheck:expired', 'local_proctorcore');
        }
    }

    /**
     * @param \stdClass $session Session.
     * @param array $result Result.
     * @return void
     */
    private function create_identity_violation(\stdClass $session, array $result): void {
        (new violation_repository())->create(
            (int) $session->id,
            'identity_substitution',
            5,
            'identity_model',
            [
                'description' => get_string('identity:substitutionviolation', 'local_proctorcore'),
                'metadata' => $result,
            ]
        );
    }

    /**
     * Sets the official local proctoring result to failed while keeping entry allowed.
     *
     * @param int $sessionid Session id.
     * @return void
     */
    private function mark_session_failed_for_identity(int $sessionid): void {
        global $DB;

        $record = (object) [
            'id' => $sessionid,
            'result' => 'failed',
            'closedreason' => 'identity_mismatch',
            'timemodified' => time(),
        ];
        $DB->update_record('local_proctorcore_sessions', $record);
    }

    /** @return string */
    private function key(int $quizid, int $userid): string {
        return $quizid . ':' . $userid;
    }
}
