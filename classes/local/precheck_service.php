<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Owns temporary browser precheck results and stores the final check record.
 *
 * Precheck data is kept in the authenticated Moodle session until Moodle has
 * created the real quiz attempt. No new database table is required.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class precheck_service {
    /** Session result lifetime. */
    private const RESULT_TTL = 900;

    /**
     * Creates a short-lived token for a quiz/user precheck form.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @return string
     */
    public function issue_token(int $quizid, int $userid): string {
        global $SESSION;

        if (!isset($SESSION->local_proctorcore_prechecks)
                || !is_array($SESSION->local_proctorcore_prechecks)) {
            $SESSION->local_proctorcore_prechecks = [];
        }

        $key = $this->key($quizid, $userid);
        $existing = $SESSION->local_proctorcore_prechecks[$key] ?? null;

        // Moodle rebuilds the form before validating the submitted POST.
        // Reuse the existing valid token so the submitted token still matches.
        if (is_array($existing)
                && !empty($existing['token'])
                && !empty($existing['issuedat'])
                && time() - (int) $existing['issuedat'] <= self::RESULT_TTL) {
            return (string) $existing['token'];
        }

        $token = bin2hex(random_bytes(24));

        $SESSION->local_proctorcore_prechecks[$key] = [
            'token' => $token,
            'issuedat' => time(),
            'result' => null,
        ];

        return $token;
    }

    /**
     * Validates and remembers a client-side precheck result.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @param array $data Submitted values.
     * @param \stdClass $config Effective quiz configuration.
     * @return array Validation errors keyed by field name.
     */
    public function validate_and_remember(
        int $quizid,
        int $userid,
        array $data,
        \stdClass $config
    ): array {
        global $DB, $SESSION;

        $errors = [];
        $key = $this->key($quizid, $userid);
        $state = $SESSION->local_proctorcore_prechecks[$key] ?? null;
        $token = (string) ($data['proctorcore_preflight_token'] ?? '');

        if (!is_array($state)
                || empty($state['token'])
                || empty($state['issuedat'])
                || time() - (int) $state['issuedat'] > self::RESULT_TTL
                || $token === ''
                || !hash_equals((string) $state['token'], $token)) {
            $errors['proctorcore_precheck_status'] =
                get_string('precheck:expired', 'local_proctorcore');
            return $errors;
        }

        $required = [
            'server' => true,
            'browser' => true,
            'network' => true,
            'secure' => !empty($config->requirehttps)
                || !empty($config->requirecamera)
                || !empty($config->requiremicrophone)
                || !empty($config->requiresnapshot)
                || !empty($config->requireidentity),
            'camera' => !empty($config->requirecamera)
                || !empty($config->requiresnapshot)
                || !empty($config->requireidentity),
            'microphone' => !empty($config->requiremicrophone),
            'lighting' => !empty($config->requirecamera) || !empty($config->requiresnapshot),
            'snapshot' => !empty($config->requiresnapshot),
        ];

        foreach ($required as $name => $needed) {
            if ($needed && empty($data['proctorcore_preflight_' . $name])) {
                $errors['proctorcore_precheck_status'] =
                    get_string('precheck:notpassed', 'local_proctorcore');
                return $errors;
            }
        }

        $quiz = $DB->get_record('quiz', ['id' => $quizid], 'id,course', MUST_EXIST);
        $companyid = (new tenant_resolver())->resolve_company_id($userid, (int) $quiz->course);
        $localconfig = (new company_config_repository())->get_effective_config($companyid);
        $speedmbps = max(0.0, (float) ($data['proctorcore_preflight_speedmbps'] ?? 0));
        $brightness = max(0, min(255, (int) ($data['proctorcore_preflight_brightness'] ?? 0)));

        if ($required['network'] && $speedmbps < (float) $localconfig->minimumspeedmbps) {
            $errors['proctorcore_precheck_status'] = get_string(
                'precheck:speedbelowminimum',
                'local_proctorcore',
                format_float((float) $localconfig->minimumspeedmbps, 1)
            );
            return $errors;
        }
        if ($required['lighting'] && $brightness < (int) $localconfig->minimumlighting) {
            $errors['proctorcore_precheck_status'] = get_string(
                'precheck:lightingbelowminimum',
                'local_proctorcore',
                (int) $localconfig->minimumlighting
            );
            return $errors;
        }

        $identityresult = null;
        if (!empty($config->requireidentity)) {
            $identityresult = (new identity_service())->get_preflight_result($quizid, $userid);
            if (!$identityresult || empty($identityresult['passed'])) {
                $errors['proctorcore_identity_status'] =
                    get_string('identity:notpassed', 'local_proctorcore');
                return $errors;
            }
        }

        if (empty($data['proctorcore_preflight_passed'])) {
            $errors['proctorcore_precheck_status'] =
                get_string('precheck:notpassed', 'local_proctorcore');
            return $errors;
        }

        $result = [
            'passed' => true,
            'serverok' => !empty($data['proctorcore_preflight_server']),
            'browserok' => !empty($data['proctorcore_preflight_browser']),
            'secureok' => !empty($data['proctorcore_preflight_secure']),
            'networkok' => !empty($data['proctorcore_preflight_network']),
            'cameraok' => !empty($data['proctorcore_preflight_camera']),
            'microphoneok' => !empty($data['proctorcore_preflight_microphone']),
            'lightingok' => !empty($data['proctorcore_preflight_lighting']),
            'snapshotok' => !empty($data['proctorcore_preflight_snapshot']),
            'speedmbps' => max(0, min(999999.99, $speedmbps)),
            'brightness' => $brightness,
            'latencyms' => max(0, min(999999,
                (int) ($data['proctorcore_preflight_latencyms'] ?? 0))),
            'browsername' => clean_param(
                (string) ($data['proctorcore_preflight_browsername'] ?? ''), PARAM_TEXT),
            'browserversion' => clean_param(
                (string) ($data['proctorcore_preflight_browserversion'] ?? ''), PARAM_TEXT),
            'checkedat' => time(),
            'identity' => $identityresult,
        ];

        $state['result'] = $result;
        $state['validatedat'] = time();
        $SESSION->local_proctorcore_prechecks[$key] = $state;
        return [];
    }

    /**
     * Returns whether a valid passed result is waiting for the attempt.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @return bool
     */
    public function has_passed_result(int $quizid, int $userid): bool {
        $result = $this->peek_result($quizid, $userid);
        return !empty($result['passed']);
    }

    /**
     * Stores a successful precheck against the real Moodle attempt/session.
     *
     * @param int $attemptid Quiz attempt id.
     * @param int $userid User id.
     * @param \stdClass $config Effective quiz configuration.
     * @return \stdClass ProctorCore session.
     */
    public function record_passed_attempt(
        int $attemptid,
        int $userid,
        \stdClass $config
    ): \stdClass {
        global $DB;

        $attempt = $DB->get_record('quiz_attempts', [
            'id' => $attemptid,
            'userid' => $userid,
        ], 'id,quiz,userid,preview,state', MUST_EXIST);

        if (!empty($attempt->preview)) {
            throw new \moodle_exception('precheck:previewattempt', 'local_proctorcore');
        }

        $repository = new session_repository();
        $existing = $repository->get_by_attempt_and_user($attemptid, $userid);
        if ($existing && $existing->techcheckstatus === 'passed') {
            // A previous Server B call may have failed after the local session
            // was created. Retry the idempotent Section 4.1 binding instead of
            // returning an unusable row with an empty server_sessionid.
            if (empty($existing->server_sessionid)) {
                return (new integration_service())->create_session_for_attempt($attemptid);
            }
            return $existing;
        }

        $result = $this->peek_result((int) $attempt->quiz, $userid);
        if (empty($result['passed'])) {
            throw new \moodle_exception('precheck:notpassed', 'local_proctorcore');
        }

        $session = (new integration_service())->create_session_for_attempt($attemptid);

        $identityrequired = !empty($config->requireidentity);
        $identitystatus = $identityrequired
            ? (!empty($result['identity']['passed']) ? 'passed' : 'failed')
            : 'notrequired';

        $repository->update_check_statuses(
            (int) $session->id,
            'passed',
            $identitystatus,
            [
                'precheck' => [
                    'serverOk' => (bool) $result['serverok'],
                    'browserOk' => (bool) $result['browserok'],
                    'secureOk' => (bool) $result['secureok'],
                    'networkOk' => (bool) $result['networkok'],
                    'cameraOk' => (bool) $result['cameraok'],
                    'microphoneOk' => (bool) $result['microphoneok'],
                    'lightingOk' => (bool) $result['lightingok'],
                    'snapshotCaptured' => (bool) $result['snapshotok'],
                    'speedMbps' => (float) $result['speedmbps'],
                    'brightness' => (int) $result['brightness'],
                    'latencyMs' => (int) $result['latencyms'],
                    'browserName' => (string) $result['browsername'],
                    'browserVersion' => (string) $result['browserversion'],
                    'checkedAt' => (int) $result['checkedat'],
                    'clientReported' => true,
                ],
                'identity' => $result['identity'] ?? [
                    'status' => $identityrequired ? 'missing' : 'notrequired',
                    'checkedAt' => time(),
                ],
            ]
        );

        (new identity_service())->apply_to_session(
            (int) $session->id,
            (int) $attempt->quiz,
            $userid,
            $identityrequired
        );

        $this->upsert_check_record((int) $session->id, $userid, $result);
        (new audit_logger())->log(
            'precheck.passed',
            (int) $session->companyid,
            (int) $session->id,
            $userid,
            [
                'attemptId' => $attemptid,
                'serverOk' => (bool) $result['serverok'],
                'browser' => (string) $result['browsername'],
                'browserVersion' => (string) $result['browserversion'],
                'speedMbps' => (float) $result['speedmbps'],
                'brightness' => (int) $result['brightness'],
                'latencyMs' => (int) $result['latencyms'],
                'cameraOk' => (bool) $result['cameraok'],
                'microphoneOk' => (bool) $result['microphoneok'],
                'lightingOk' => (bool) $result['lightingok'],
                'snapshotCaptured' => (bool) $result['snapshotok'],
                'identityStatus' => $identitystatus,
                'identityScore' => $result['identity']['score'] ?? null,
                'identityThreshold' => $result['identity']['threshold'] ?? null,
                'livenessPassed' => $result['identity']['livenessPassed'] ?? null,
            ],
            $userid,
            'session',
            (int) $session->id
        );
        $this->forget_result((int) $attempt->quiz, $userid);

        return $repository->get_by_id((int) $session->id);
    }

    /**
     * Gets the current temporary result without removing it.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @return array|null
     */
    private function peek_result(int $quizid, int $userid): ?array {
        global $SESSION;

        $key = $this->key($quizid, $userid);
        $state = $SESSION->local_proctorcore_prechecks[$key] ?? null;
        if (!is_array($state)
                || empty($state['result'])
                || !is_array($state['result'])
                || empty($state['validatedat'])
                || time() - (int) $state['validatedat'] > self::RESULT_TTL) {
            return null;
        }
        return $state['result'];
    }

    /**
     * Removes the temporary precheck result.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @return void
     */
    private function forget_result(int $quizid, int $userid): void {
        global $SESSION;
        $key = $this->key($quizid, $userid);
        unset($SESSION->local_proctorcore_prechecks[$key]);
    }

    /**
     * Inserts or updates the Moodle technical-check record.
     *
     * @param int $sessionid Session id.
     * @param int $userid User id.
     * @param array $result Check result.
     * @return void
     */
    private function upsert_check_record(int $sessionid, int $userid, array $result): void {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_proctorcore_checks'))) {
            return;
        }

        $record = $DB->get_record('local_proctorcore_checks', [
            'sessionid' => $sessionid,
            'userid' => $userid,
        ], '*', IGNORE_MULTIPLE);

        $payload = (object) [
            'sessionid' => $sessionid,
            'userid' => $userid,
            'status' => 'passed',
            'speed_mbps' => round((float) $result['speedmbps'], 2),
            'cameraok' => !empty($result['cameraok']) ? 1 : 0,
            'microphoneok' => !empty($result['microphoneok']) ? 1 : 0,
            'lightingok' => !empty($result['lightingok']) ? 1 : 0,
            'browsername' => substr((string) $result['browsername'], 0, 64),
            'browserversion' => substr((string) $result['browserversion'], 0, 64),
            'failurejson' => json_encode([
                'secureOk' => (bool) $result['secureok'],
                'networkOk' => (bool) $result['networkok'],
                'snapshotCaptured' => (bool) $result['snapshotok'],
                'latencyMs' => (int) $result['latencyms'],
                'brightness' => (int) $result['brightness'],
                'clientReported' => true,
            ], JSON_UNESCAPED_SLASHES),
            'checkedat' => (int) $result['checkedat'],
            'timecreated' => (int) $result['checkedat'],
        ];

        if ($record) {
            $payload->id = (int) $record->id;
            $payload->timecreated = (int) $record->timecreated;
            $DB->update_record('local_proctorcore_checks', $payload);
        } else {
            $DB->insert_record('local_proctorcore_checks', $payload);
        }
    }

    /**
     * Builds a stable session array key.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @return string
     */
    private function key(int $quizid, int $userid): string {
        return $quizid . ':' . $userid;
    }
}
