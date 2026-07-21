<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Section 1.2 identity verification service.
 *
 * Pre-attempt identity results live in the authenticated Moodle session until
 * Moodle creates the real Quiz attempt. The successful live image is then
 * copied into the normal protected evidence store and linked to the session.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class identity_service {
    /** Result lifetime, seconds. */
    private const RESULT_TTL = 900;

    /**
     * Verifies the live three-frame challenge against the Moodle profile photo.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @param string $token Current preflight token.
     * @param string $centerdata Data URL/base64 centre frame.
     * @param string $leftdata Data URL/base64 left-turn frame.
     * @param string $rightdata Data URL/base64 right-turn frame.
     * @return array Public response.
     */
    public function verify_preflight(
        int $quizid,
        int $userid,
        string $token,
        string $centerdata,
        string $leftdata,
        string $rightdata
    ): array {
        global $DB, $SESSION;

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
                'checkedAt' => time(),
                'transactionId' => 'identity-disabled-' . $quizid . '-' . $userid,
            ];
            $this->remember($quizid, $userid, $result, null);
            return $this->public_result($result);
        }

        $reference = $this->get_profile_image_bytes($userid);
        if ($reference === null) {
            throw new \moodle_exception('identity:profilphotomissing', 'local_proctorcore');
        }

        $center = $this->decode_image($centerdata);
        $left = $this->decode_image($leftdata);
        $right = $this->decode_image($rightdata);
        $transactionid = bin2hex(random_bytes(16));

        $response = (new ml_client($companyid))->verify_identity(
            $reference,
            $center,
            $left,
            $right,
            $transactionid
        );

        $status = clean_param((string) ($response['result'] ?? 'verification_error'), PARAM_ALPHANUMEXT);
        $passed = $status === 'matched' && !empty($response['livenessPassed']);
        $result = [
            'passed' => $passed,
            'status' => $status,
            'score' => isset($response['similarityScore']) ? (float) $response['similarityScore'] : null,
            'threshold' => isset($response['threshold'])
                ? (float) $response['threshold']
                : (float) $config->identitythreshold,
            'livenessPassed' => !empty($response['livenessPassed']),
            'referenceFaceCount' => (int) ($response['referenceFaceCount'] ?? 0),
            'liveFaceCount' => (int) ($response['liveFaceCount'] ?? 0),
            'leftYaw' => isset($response['leftYaw']) ? (float) $response['leftYaw'] : null,
            'rightYaw' => isset($response['rightYaw']) ? (float) $response['rightYaw'] : null,
            'quality' => $response['quality'] ?? null,
            'checkedAt' => time(),
            'transactionId' => $transactionid,
        ];
        $this->remember($quizid, $userid, $result, $center);

        (new audit_logger())->log(
            $passed ? 'identity.preflight_passed' : 'identity.preflight_failed',
            $companyid,
            null,
            $userid,
            [
                'quizId' => $quizid,
                'status' => $status,
                'score' => $result['score'],
                'threshold' => $result['threshold'],
                'livenessPassed' => $result['livenessPassed'],
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
        $state = $SESSION->local_proctorcore_identity[$key] ?? null;
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

        $imagepath = is_array($state) ? (string) ($state['imagePath'] ?? '') : '';
        if ($imagepath !== '' && is_readable($imagepath)) {
            $this->store_identity_asset($session, $imagepath, $result);
            @unlink($imagepath);
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
                'livenessPassed' => $result['livenessPassed'],
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
     * Returns the user's protected profile photo bytes, or null.
     *
     * @param int $userid User id.
     * @return string|null
     */
    public function get_profile_image_bytes(int $userid): ?string {
        $context = \context_user::instance($userid);
        $files = get_file_storage()->get_area_files(
            $context->id,
            'user',
            'icon',
            0,
            'filesize DESC, id DESC',
            false
        );
        foreach ($files as $file) {
            if ($file->get_filesize() > 0 && strpos((string) $file->get_mimetype(), 'image/') === 0) {
                return $file->get_content();
            }
        }
        return null;
    }

    /** @return array */
    private function public_result(array $result): array {
        return [
            'ok' => true,
            'passed' => !empty($result['passed']),
            'result' => (string) $result['status'],
            'similarityScore' => $result['score'],
            'threshold' => $result['threshold'],
            'livenessPassed' => !empty($result['livenessPassed']),
            'message' => get_string(
                !empty($result['passed']) ? 'identity:passed' : 'identity:failed',
                'local_proctorcore'
            ),
        ];
    }

    /**
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @param array $result Result.
     * @param string|null $centerbytes Live image bytes.
     * @return void
     */
    private function remember(int $quizid, int $userid, array $result, ?string $centerbytes): void {
        global $CFG, $SESSION;
        if (!isset($SESSION->local_proctorcore_identity)
                || !is_array($SESSION->local_proctorcore_identity)) {
            $SESSION->local_proctorcore_identity = [];
        }
        $path = null;
        if ($centerbytes !== null) {
            $dir = make_temp_directory('proctorcore_identity');
            $path = $dir . '/' . sha1($quizid . ':' . $userid . ':' . microtime(true)) . '.jpg';
            if (file_put_contents($path, $centerbytes, LOCK_EX) === false) {
                throw new \moodle_exception('identity:tempwritefailed', 'local_proctorcore');
            }
            @chmod($path, $CFG->filepermissions);
        }
        $SESSION->local_proctorcore_identity[$this->key($quizid, $userid)] = [
            'result' => $result,
            'imagePath' => $path,
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
     * @param string $path Temp image path.
     * @param array $result Identity result.
     * @return void
     */
    private function store_identity_asset(\stdClass $session, string $path, array $result): void {
        $context = \context_system::instance();
        $fs = get_file_storage();
        $filename = 'identity-session-' . (int) $session->id . '.jpg';
        $fs->delete_area_files($context->id, 'local_proctorcore', 'identity', (int) $session->id);
        $file = $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'local_proctorcore',
            'filearea' => 'identity',
            'itemid' => (int) $session->id,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => (int) $session->userid,
        ], $path);

        (new asset_repository())->create(
            (int) $session->id,
            (int) $session->companyid,
            asset_repository::TYPE_IDENTITY_PHOTO,
            [
                'storage' => 'moodle_file',
                'filearea' => 'identity',
                'itemid' => (int) $session->id,
                'checksum' => $file->get_contenthash(),
                'mime' => $file->get_mimetype(),
                'filesize' => $file->get_filesize(),
                'metadata' => [
                    'filename' => $filename,
                    'reason' => 'identity_verification',
                    'similarityScore' => $result['score'],
                    'threshold' => $result['threshold'],
                    'livenessPassed' => $result['livenessPassed'],
                    'transactionId' => $result['transactionId'],
                ],
            ]
        );
        (new session_repository())->increment_snapshot_count((int) $session->id);
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

    /** @return string */
    private function key(int $quizid, int $userid): string {
        return $quizid . ':' . $userid;
    }
}
