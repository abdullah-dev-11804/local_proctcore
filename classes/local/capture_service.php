<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Section 1.1 browser media capture coordinator.
 *
 * Moodle owns authorisation and session state. Server B owns the LiveKit room,
 * server-side recording, snapshot extraction, and binary media storage.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class capture_service {
    /** @var session_repository */
    private $sessions;

    /** @var company_config_repository */
    private $configs;

    /** @var audit_logger */
    private $audit;

    /**
     * Constructor.
     *
     * @param session_repository|null $sessions Session repository.
     * @param company_config_repository|null $configs Configuration repository.
     * @param audit_logger|null $audit Audit logger.
     */
    public function __construct(
        ?session_repository $sessions = null,
        ?company_config_repository $configs = null,
        ?audit_logger $audit = null
    ) {
        $this->sessions = $sessions ?? new session_repository();
        $this->configs = $configs ?? new company_config_repository();
        $this->audit = $audit ?? new audit_logger();
    }

    /**
     * Returns a short-lived LiveKit connection bootstrap to the session owner.
     *
     * @param int $sessionid Local session id.
     * @param int $userid Current Moodle user id.
     * @return array
     */
    public function bootstrap(int $sessionid, int $userid): array {
        $session = $this->require_session($sessionid, $userid, true);

        if (local_capture_storage::is_enabled()) {
            $path = local_capture_storage::get_base_path(true);
            $this->audit->log(
                'capture.bootstrap_issued',
                (int) $session->companyid,
                (int) $session->id,
                (int) $session->userid,
                [
                    'attemptId' => (int) $session->attemptid,
                    'serverSessionId' => (string) $session->server_sessionid,
                    'roomId' => (string) $session->server_roomid,
                    'mode' => 'localtest',
                    'storagePathConfigured' => $path !== '',
                    'tokenStored' => false,
                ],
                $userid,
                'session',
                (int) $session->id
            );

            return [
                'ok' => true,
                'mode' => 'localtest',
                'sessionId' => (int) $session->id,
                'attemptId' => (int) $session->attemptid,
                'serverSessionId' => (string) $session->server_sessionid,
                'roomId' => (string) $session->server_roomid,
                'uploadUrl' => (new \moodle_url('/local/proctorcore/local_upload.php'))->out(false),
                'chunkMilliseconds' => 5000,
            ];
        }

        $client = new server_client((int) $session->companyid, $this->configs);
        $response = $client->create_media_token((string) $session->server_sessionid, [
            'moodleSessionId' => (int) $session->id,
            'attemptId' => (int) $session->attemptid,
            'quizId' => (int) $session->quizid,
            'courseId' => (int) $session->courseid,
            'companyId' => (int) $session->companyid,
            'userId' => (int) $session->userid,
            'participantIdentity' => 'moodle-user-' . (int) $session->userid . '-attempt-' . (int) $session->attemptid,
            'participantName' => fullname(\core_user::get_user((int) $session->userid, '*', MUST_EXIST)),
            'permissions' => [
                'canPublish' => true,
                'canPublishCamera' => true,
                'canPublishMicrophone' => true,
                'canSubscribe' => false,
            ],
            'requestedAt' => gmdate('c'),
        ]);

        $connection = $this->normalise_connection($response);
        $config = $this->configs->get_effective_config((int) $session->companyid);
        if ($connection['clientScriptUrl'] === '' && !empty($config->livekitclienturl)) {
            $connection['clientScriptUrl'] = (string) $config->livekitclienturl;
        }

        if ($connection['url'] === '' || $connection['token'] === '') {
            throw new \moodle_exception('error:mediaconnectionmissing', 'local_proctorcore');
        }
        if ($connection['clientScriptUrl'] === '') {
            throw new \moodle_exception('error:livekitsdkmissing', 'local_proctorcore');
        }

        $this->audit->log(
            'capture.bootstrap_issued',
            (int) $session->companyid,
            (int) $session->id,
            (int) $session->userid,
            [
                'attemptId' => (int) $session->attemptid,
                'serverSessionId' => (string) $session->server_sessionid,
                'roomId' => (string) ($connection['roomId'] ?: $session->server_roomid),
                'tokenStored' => false,
            ],
            $userid,
            'session',
            (int) $session->id
        );

        return [
            'ok' => true,
            'mode' => 'serverb',
            'sessionId' => (int) $session->id,
            'serverSessionId' => (string) $session->server_sessionid,
            'roomId' => (string) ($connection['roomId'] ?: $session->server_roomid),
            'url' => $connection['url'],
            'token' => $connection['token'],
            'clientScriptUrl' => $connection['clientScriptUrl'],
            'tokenExpiresAt' => $connection['tokenExpiresAt'],
        ];
    }

    /**
     * Starts the current server-side recording segment.
     *
     * Repeated calls while the segment is active are idempotent.
     *
     * @param int $sessionid Local session id.
     * @param int|null $userid Current user id, or null for a trusted server observer.
     * @param string $reason Start reason.
     * @return array
     */
    public function start_capture(int $sessionid, ?int $userid, string $reason = 'attempt_page_connected'): array {
        $session = $this->require_session($sessionid, $userid, true);
        $lock = $this->acquire_lock($sessionid);

        try {
            $session = $this->require_session($sessionid, $userid, true);
            $metadata = $this->decode_metadata($session->servermetadata);
            $capture = is_array($metadata['capture'] ?? null) ? $metadata['capture'] : [];

            if (($capture['recordingState'] ?? '') === 'active') {
                return [
                    'ok' => true,
                    'status' => 'active',
                    'segment' => (int) ($capture['currentSegment'] ?? 1),
                    'duplicate' => true,
                ];
            }

            $segment = max(1, (int) ($capture['currentSegment'] ?? 0) + 1);
            $now = time();
            $cleanreason = clean_param($reason, PARAM_ALPHANUMEXT) ?: 'attempt_page_connected';
            $idempotencykey = 'recording-start-' . (int) $session->id . '-' . $segment;
            if (local_capture_storage::is_enabled()) {
                $recordingid = 'local-recording-' . (int) $session->id . '-' . $segment;
            } else {
                $response = (new server_client((int) $session->companyid, $this->configs))->start_recording(
                    (string) $session->server_sessionid,
                    [
                        'moodleSessionId' => (int) $session->id,
                        'attemptId' => (int) $session->attemptid,
                        'companyId' => (int) $session->companyid,
                        'userId' => (int) $session->userid,
                        'segment' => $segment,
                        'reason' => $cleanreason,
                        'startedAt' => gmdate('c', $now),
                        'idempotencyKey' => $idempotencykey,
                    ]
                );

                $recordingid = $this->first_string($response, [
                    'recordingId', 'recording.id', 'recording.recordingId', 'id',
                ]);
            }
            $segmententry = [
                'segment' => $segment,
                'recordingId' => $recordingid,
                'startedAt' => $now,
                'stoppedAt' => null,
                'startReason' => $cleanreason,
                'stopReason' => null,
            ];
            $segments = is_array($capture['segments'] ?? null) ? $capture['segments'] : [];
            $segments[(string) $segment] = $segmententry;

            $this->sessions->merge_server_metadata((int) $session->id, [
                'capture' => [
                    'recordingState' => 'active',
                    'currentSegment' => $segment,
                    'recordingId' => $recordingid,
                    'lastStartedAt' => $now,
                    'lastError' => null,
                    'segments' => $segments,
                ],
            ]);

            $this->audit->log(
                'capture.recording_started',
                (int) $session->companyid,
                (int) $session->id,
                (int) $session->userid,
                [
                    'attemptId' => (int) $session->attemptid,
                    'segment' => $segment,
                    'recordingId' => $recordingid,
                    'reason' => $cleanreason,
                ],
                $userid,
                'session',
                (int) $session->id
            );

            return [
                'ok' => true,
                'status' => 'active',
                'segment' => $segment,
                'recordingId' => $recordingid,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Requests a key-moment snapshot.
     *
     * @param int $sessionid Local session id.
     * @param int|null $userid Current user id, or null for trusted server code.
     * @param string $reason identity_verification, violation, submission, or manual_proctor.
     * @param int|null $violationid Optional local violation id.
     * @return array
     */
    public function request_snapshot(
        int $sessionid,
        ?int $userid,
        string $reason,
        ?int $violationid = null
    ): array {
        $allowed = ['identity_verification', 'violation', 'submission', 'manual_proctor'];
        if (!in_array($reason, $allowed, true)) {
            throw new \moodle_exception('error:invalidsnapshotreason', 'local_proctorcore');
        }

        $this->require_session($sessionid, $userid, false);
        $lock = $this->acquire_lock($sessionid);

        try {
            $session = $this->require_session($sessionid, $userid, false);
            $metadata = $this->decode_metadata($session->servermetadata);
            $capture = is_array($metadata['capture'] ?? null) ? $metadata['capture'] : [];
            $snapshotflags = is_array($capture['snapshotRequests'] ?? null)
                ? $capture['snapshotRequests']
                : [];

            $stablekey = $reason === 'violation'
                ? 'violation-' . ($violationid ?: time())
                : $reason;
            if (!empty($snapshotflags[$stablekey]['requestedAt'])) {
                return [
                    'ok' => true,
                    'status' => 'already_requested',
                    'reason' => $reason,
                    'duplicate' => true,
                ];
            }

            $now = time();
            $payload = [
                'moodleSessionId' => (int) $session->id,
                'attemptId' => (int) $session->attemptid,
                'companyId' => (int) $session->companyid,
                'userId' => (int) $session->userid,
                'reason' => $reason,
                'capturedAt' => gmdate('c', $now),
                'idempotencyKey' => 'snapshot-' . (int) $session->id . '-' . $stablekey,
            ];
            if ($violationid !== null && $violationid > 0) {
                $payload['violationId'] = $violationid;
            }

            if (local_capture_storage::is_enabled()) {
                // In local mode the browser uploads the actual camera frame to
                // local_upload.php. Server-side observers cannot take a browser
                // snapshot, so this method records only the request marker.
                $serverrequestid = 'browser-upload-required';
            } else {
                $response = (new server_client((int) $session->companyid, $this->configs))->capture_snapshot(
                    (string) $session->server_sessionid,
                    $payload
                );
                $serverrequestid = $this->first_string($response, [
                    'requestId', 'snapshotRequestId', 'snapshot.requestId', 'snapshot.id', 'id',
                ]);
            }

            $snapshotflags[$stablekey] = [
                'reason' => $reason,
                'violationId' => $violationid,
                'requestedAt' => $now,
                'serverRequestId' => $serverrequestid,
            ];
            $this->sessions->merge_server_metadata((int) $session->id, [
                'capture' => [
                    'snapshotRequests' => $snapshotflags,
                ],
            ]);

            $this->audit->log(
                'capture.snapshot_requested',
                (int) $session->companyid,
                (int) $session->id,
                (int) $session->userid,
                [
                    'attemptId' => (int) $session->attemptid,
                    'reason' => $reason,
                    'violationId' => $violationid,
                ],
                $userid,
                'session',
                (int) $session->id
            );

            return [
                'ok' => true,
                'status' => 'requested',
                'reason' => $reason,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Stops/finalises the active recording segment.
     *
     * @param int $sessionid Local session id.
     * @param int|null $userid Current user id, or null for trusted server code.
     * @param string $reason Stop reason.
     * @return array
     */
    public function stop_capture(int $sessionid, ?int $userid, string $reason = 'submitted'): array {
        $session = $this->require_session($sessionid, $userid, false);
        $lock = $this->acquire_lock($sessionid);

        try {
            $session = $this->require_session($sessionid, $userid, false);
            $metadata = $this->decode_metadata($session->servermetadata);
            $capture = is_array($metadata['capture'] ?? null) ? $metadata['capture'] : [];
            if (($capture['recordingState'] ?? '') !== 'active') {
                return [
                    'ok' => true,
                    'status' => (string) ($capture['recordingState'] ?? 'not_started'),
                    'duplicate' => true,
                ];
            }

            $segment = max(1, (int) ($capture['currentSegment'] ?? 1));
            $now = time();
            $cleanreason = clean_param($reason, PARAM_ALPHANUMEXT) ?: 'submitted';
            if (local_capture_storage::is_enabled()) {
                $response = [];
            } else {
                $response = (new server_client((int) $session->companyid, $this->configs))->stop_recording(
                    (string) $session->server_sessionid,
                    [
                        'moodleSessionId' => (int) $session->id,
                        'attemptId' => (int) $session->attemptid,
                        'companyId' => (int) $session->companyid,
                        'userId' => (int) $session->userid,
                        'segment' => $segment,
                        'reason' => $cleanreason,
                        'stoppedAt' => gmdate('c', $now),
                        'idempotencyKey' => 'recording-stop-' . (int) $session->id . '-' . $segment,
                    ]
                );
            }

            $segments = is_array($capture['segments'] ?? null) ? $capture['segments'] : [];
            $entry = is_array($segments[(string) $segment] ?? null)
                ? $segments[(string) $segment]
                : ['segment' => $segment];
            $entry['stoppedAt'] = $now;
            $entry['stopReason'] = $cleanreason;
            $entry['assetId'] = $this->first_string($response, [
                'assetId', 'asset.id', 'recording.assetId',
            ]);
            $segments[(string) $segment] = $entry;

            $state = in_array($cleanreason, ['connection_lost', 'media_track_ended', 'browser_offline', 'media_device_error',
                    'media_connection_disconnected'], true)
                ? 'interrupted'
                : 'stopped';
            $this->sessions->merge_server_metadata((int) $session->id, [
                'capture' => [
                    'recordingState' => $state,
                    'lastStoppedAt' => $now,
                    'lastStopReason' => $cleanreason,
                    'segments' => $segments,
                ],
            ]);

            $this->audit->log(
                'capture.recording_stopped',
                (int) $session->companyid,
                (int) $session->id,
                (int) $session->userid,
                [
                    'attemptId' => (int) $session->attemptid,
                    'segment' => $segment,
                    'reason' => $cleanreason,
                    'assetId' => $entry['assetId'],
                ],
                $userid,
                'session',
                (int) $session->id
            );

            return [
                'ok' => true,
                'status' => $state,
                'segment' => $segment,
                'assetId' => $entry['assetId'],
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Records a non-fatal browser media failure and finalises the partial segment.
     *
     * @param int $sessionid Local session id.
     * @param int $userid Current user id.
     * @param string $reason Machine-readable reason.
     * @param string $message Optional client message.
     * @return array
     */
    public function media_failure(int $sessionid, int $userid, string $reason, string $message = ''): array {
        $session = $this->require_session($sessionid, $userid, false);
        $cleanreason = clean_param($reason, PARAM_ALPHANUMEXT);
        $this->sessions->merge_server_metadata((int) $session->id, [
            'capture' => [
                'lastError' => [
                    'reason' => $cleanreason,
                    'message' => clean_param($message, PARAM_TEXT),
                    'time' => time(),
                ],
            ],
        ]);

        try {
            return $this->stop_capture($sessionid, $userid, $cleanreason ?: 'media_track_ended');
        } catch (\Throwable $exception) {
            debugging('ProctorCore could not finalise the partial recording: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return [
                'ok' => false,
                'status' => 'error_recorded',
                'message' => clean_param($exception->getMessage(), PARAM_TEXT),
            ];
        }
    }

    /**
     * Requires a valid session and optional owner.
     *
     * @param int $sessionid Local session id.
     * @param int|null $userid Owner id or null for trusted server code.
     * @param bool $requireactive Require active state.
     * @return \stdClass
     */
    private function require_session(int $sessionid, ?int $userid, bool $requireactive): \stdClass {
        $session = $this->sessions->get_by_id($sessionid);
        if ($userid !== null && (int) $session->userid !== $userid) {
            throw new \moodle_exception('error:sessionowner', 'local_proctorcore');
        }
        if (empty($session->server_sessionid)) {
            throw new \moodle_exception('error:serversessionmissing', 'local_proctorcore');
        }
        if (in_array((string) $session->status, ['completed', 'failed', 'abandoned', 'expired'], true)) {
            throw new \moodle_exception('error:captureclosed', 'local_proctorcore');
        }
        if ($requireactive && (string) $session->status !== 'active') {
            throw new \moodle_exception('error:capturenotactive', 'local_proctorcore');
        }
        if ((string) $session->techcheckstatus !== 'passed') {
            throw new \moodle_exception('error:captureprecheckrequired', 'local_proctorcore');
        }
        return $session;
    }

    /** @return \core\lock\lock */
    private function acquire_lock(int $sessionid) {
        $factory = \core\lock\lock_config::get_lock_factory('local_proctorcore_capture');
        $lock = $factory->get_lock('session_' . $sessionid, 15);
        if (!$lock) {
            throw new \moodle_exception('error:capturebusy', 'local_proctorcore');
        }
        return $lock;
    }

    /** @return array */
    private function decode_metadata(?string $json): array {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Normalises several compatible Server B media-token response shapes.
     *
     * @param array $response Response body.
     * @return array
     */
    private function normalise_connection(array $response): array {
        return [
            'url' => $this->first_string($response, [
                'url', 'wsUrl', 'livekitUrl', 'serverUrl',
                'media.url', 'media.wsUrl', 'media.livekitUrl',
                'connection.url', 'connection.wsUrl', 'connection.livekitUrl',
                'session.url', 'session.wsUrl',
            ]),
            'token' => $this->first_string($response, [
                'token', 'accessToken', 'participantToken',
                'media.token', 'media.accessToken', 'media.participantToken',
                'connection.token', 'connection.accessToken', 'connection.participantToken',
                'session.token', 'session.accessToken',
            ]),
            'roomId' => $this->first_string($response, [
                'roomId', 'roomName', 'media.roomId', 'media.roomName',
                'connection.roomId', 'connection.roomName', 'session.roomId',
            ]),
            'clientScriptUrl' => $this->first_string($response, [
                'clientScriptUrl', 'sdkUrl', 'media.clientScriptUrl', 'media.sdkUrl',
                'connection.clientScriptUrl', 'connection.sdkUrl',
            ]),
            'tokenExpiresAt' => $this->first_string($response, [
                'tokenExpiresAt', 'expiresAt', 'media.tokenExpiresAt',
                'connection.tokenExpiresAt',
            ]),
        ];
    }

    /**
     * Finds the first non-empty scalar at a dotted path.
     *
     * @param array $data Source array.
     * @param array $paths Dotted paths.
     * @return string
     */
    private function first_string(array $data, array $paths): string {
        foreach ($paths as $path) {
            $value = $data;
            foreach (explode('.', $path) as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$part];
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }
        return '';
    }
}
