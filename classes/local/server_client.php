<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * HTTP client for the external ProctorCore Server B API.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class server_client {
    /** @var \stdClass Effective tenant configuration. */
    private $config;

    /** @var int IOMAD company id. */
    private $companyid;

    /**
     * Constructor.
     *
     * @param int $companyid Tenant company id.
     * @param company_config_repository|null $configrepository Optional dependency.
     */
    public function __construct(int $companyid, ?company_config_repository $configrepository = null) {
        $repository = $configrepository ?? new company_config_repository();
        $this->config = $repository->require_enabled_config($companyid);
        $this->companyid = $companyid;
    }

    /** @return array */
    public function health(): array {
        return $this->request('GET', '/api/health');
    }

    /**
     * Verifies a pre-attempt identity challenge with Server B.
     *
     * @param string $referencebytes Moodle profile image bytes.
     * @param array $centerframes Straight-looking camera frames.
     * @param array $leftframes Left-turn challenge frames.
     * @param array $rightframes Right-turn challenge frames.
     * @param string $transactionid Correlation id.
     * @param float $threshold Tenant threshold.
     * @return array
     */
    public function verify_identity(
        string $referencebytes,
        array $centerframes,
        array $leftframes,
        array $rightframes,
        string $transactionid,
        float $threshold
    ): array {
        $encode = static function(string $bytes): string {
            return base64_encode($bytes);
        };

        return $this->request('POST', '/api/v1/identity/verify', [
            'transactionId' => $transactionid,
            'companyId' => $this->companyid,
            'threshold' => $threshold,
            'referenceImage' => base64_encode($referencebytes),
            'centerImage' => base64_encode($centerframes[0] ?? ''),
            'leftImage' => base64_encode($leftframes[0] ?? ''),
            'rightImage' => base64_encode($rightframes[0] ?? ''),
            'centerImages' => array_map($encode, $centerframes),
            'leftImages' => array_map($encode, $leftframes),
            'rightImages' => array_map($encode, $rightframes),
        ]);
    }

    /**
     * Stores the user's first reusable face reference on Server B.
     *
     * @param int $userid Moodle user id.
     * @param array $centerframes Straight-looking camera frames.
     * @param string $transactionid Correlation id.
     * @param string $fullname Profile full name confirmed by the user.
     * @param int $confirmedat Confirmation timestamp.
     * @param float $threshold Tenant threshold.
     * @return array
     */
    public function enroll_face_reference(
        int $userid,
        array $centerframes,
        array $leftframes,
        array $rightframes,
        string $transactionid,
        string $fullname,
        int $confirmedat,
        float $threshold
    ): array {
        $encode = static function(string $bytes): string {
            return base64_encode($bytes);
        };

        return $this->request('POST', '/api/v1/identity/references/enroll', [
            'transactionId' => $transactionid,
            'companyId' => $this->companyid,
            'userId' => $userid,
            'fullName' => $fullname,
            'confirmedAt' => $confirmedat,
            'threshold' => $threshold,
            'centerImages' => array_map($encode, $centerframes),
            'leftImages' => array_map($encode, $leftframes),
            'rightImages' => array_map($encode, $rightframes),
        ]);
    }

    /**
     * Verifies a live face burst against the stored Server B reference.
     *
     * @param int $userid Moodle user id.
     * @param array $centerframes Straight-looking camera frames.
     * @param string $transactionid Correlation id.
     * @param float $threshold Tenant threshold.
     * @return array
     */
    public function verify_face_reference(
        int $userid,
        array $centerframes,
        array $leftframes,
        array $rightframes,
        string $transactionid,
        float $threshold
    ): array {
        $encode = static function(string $bytes): string {
            return base64_encode($bytes);
        };

        return $this->request('POST', '/api/v1/identity/references/verify', [
            'transactionId' => $transactionid,
            'companyId' => $this->companyid,
            'userId' => $userid,
            'threshold' => $threshold,
            'centerImages' => array_map($encode, $centerframes),
            'leftImages' => array_map($encode, $leftframes),
            'rightImages' => array_map($encode, $rightframes),
        ]);
    }

    /**
     * Removes a stored reference from Server B.
     *
     * @param int $userid Moodle user id.
     * @param string $reason Administrator supplied reason.
     * @return array
     */
    public function reset_face_reference(int $userid, string $reason): array {
        return $this->request(
            'DELETE',
            '/api/v1/identity/references/' . $userid . '?companyId=' . $this->companyid,
            ['reason' => $reason]
        );
    }

    /** @param array $payload @return array */
    public function create_session(array $payload): array {
        return $this->request('POST', '/api/v1/sessions', $payload);
    }

    /** @param string $serversessionid @return array */
    public function get_session(string $serversessionid): array {
        return $this->request('GET', '/api/v1/sessions/' . rawurlencode($serversessionid));
    }

    /** @param string $serversessionid @param array $payload @return array */
    public function start_session(string $serversessionid, array $payload = []): array {
        return $this->request('POST', '/api/v1/sessions/' . rawurlencode($serversessionid) . '/start', $payload);
    }

    /** @param string $serversessionid @param array $payload @return array */
    public function heartbeat(string $serversessionid, array $payload): array {
        return $this->request('POST', '/api/v1/sessions/' . rawurlencode($serversessionid) . '/heartbeat', $payload);
    }

    /**
     * Creates a short-lived browser participant token for the LiveKit room.
     *
     * Server B may return token/url directly or inside media/connection/session
     * objects.
     *
     * @param string $serversessionid External session id.
     * @param array $payload Browser/participant metadata.
     * @return array
     */
    public function create_media_token(string $serversessionid, array $payload): array {
        return $this->request(
            'POST',
            '/api/v1/sessions/' . rawurlencode($serversessionid) . '/media-token',
            $payload
        );
    }

    /**
     * Starts or resumes a Server B recording segment.
     *
     * @param string $serversessionid External session id.
     * @param array $payload Recording metadata and idempotency key.
     * @return array
     */
    public function start_recording(string $serversessionid, array $payload): array {
        return $this->request(
            'POST',
            '/api/v1/sessions/' . rawurlencode($serversessionid) . '/recording/start',
            $payload
        );
    }

    /**
     * Finalises the current Server B recording segment.
     *
     * @param string $serversessionid External session id.
     * @param array $payload Stop reason and idempotency key.
     * @return array
     */
    public function stop_recording(string $serversessionid, array $payload): array {
        return $this->request(
            'POST',
            '/api/v1/sessions/' . rawurlencode($serversessionid) . '/recording/stop',
            $payload
        );
    }

    /**
     * Requests a key-moment snapshot from the active camera publication.
     *
     * @param string $serversessionid External session id.
     * @param array $payload Snapshot reason, violation id, and idempotency key.
     * @return array
     */
    public function capture_snapshot(string $serversessionid, array $payload): array {
        return $this->request(
            'POST',
            '/api/v1/sessions/' . rawurlencode($serversessionid) . '/snapshots',
            $payload
        );
    }

    /**
     * Permanently removes an expired media object from Server B storage.
     *
     * @param string $assetid External asset id.
     * @param array $payload Optional deletion/audit metadata.
     * @return array
     */
    public function delete_asset(string $assetid, array $payload = []): array {
        return $this->request('DELETE', '/api/v1/assets/' . rawurlencode($assetid), $payload);
    }

    /**
     * Marks a Server B session interrupted.
     *
     * @param string $serversessionid External session id.
     * @param array $payload Interruption metadata.
     * @return array
     */
    public function interrupt_session(string $serversessionid, array $payload): array {
        return $this->request('POST', '/api/v1/sessions/' . rawurlencode($serversessionid) . '/interrupt', $payload);
    }

    /**
     * Resumes the same Server B session.
     *
     * @param string $serversessionid External session id.
     * @param array $payload Resume metadata.
     * @return array
     */
    public function resume_session(string $serversessionid, array $payload): array {
        return $this->request('POST', '/api/v1/sessions/' . rawurlencode($serversessionid) . '/resume', $payload);
    }

    /**
     * Asks Server B to fail/abandon a session after the reconnect window.
     *
     * Server B should then send the normal Section 4.2 signed Failed webhook.
     *
     * @param string $serversessionid External session id.
     * @param array $payload Failure metadata.
     * @return array
     */
    public function fail_session(string $serversessionid, array $payload): array {
        return $this->request('POST', '/api/v1/sessions/' . rawurlencode($serversessionid) . '/fail', $payload);
    }

    /**
     * Performs a JSON API request.
     *
     * @param string $method HTTP method.
     * @param string $path Relative API path.
     * @param array|null $payload Optional request payload.
     * @return array Decoded JSON response.
     */
    private function request(string $method, string $path, ?array $payload = null): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $url = rtrim($this->config->serverbaseurl, '/') . '/' . ltrim($path, '/');
        $curl = new \curl();

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-ProctorCore-Company: ' . $this->companyid,
            'X-ProctorCore-Source: moodle-local-proctorcore',
        ];
        if ($this->config->serverapikey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->config->serverapikey;
        }
        $curl->setHeader($headers);

        $options = [
            'CURLOPT_CONNECTTIMEOUT' => $this->config->connecttimeout,
            'CURLOPT_TIMEOUT' => $this->config->requesttimeout,
            'CURLOPT_SSL_VERIFYPEER' => $this->config->verifyssl,
            'CURLOPT_SSL_VERIFYHOST' => $this->config->verifyssl ? 2 : 0,
        ];

        $rawresponse = '';
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload !== null && $body === false) {
            throw new \coding_exception('Unable to encode Server B request payload.');
        }

        try {
            switch (strtoupper($method)) {
                case 'GET':
                    $rawresponse = $curl->get($url, [], $options);
                    break;
                case 'POST':
                    $rawresponse = $curl->post($url, $body, $options);
                    break;
                case 'DELETE':
                    $rawresponse = $curl->delete($url, $body, $options);
                    break;
                default:
                    throw new \coding_exception('Unsupported Server B HTTP method: ' . $method);
            }
        } catch (\Throwable $exception) {
            throw new \moodle_exception(
                'error:serverunavailable',
                'local_proctorcore',
                '',
                null,
                $exception->getMessage()
            );
        }

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);
        $textresponse = (string) $rawresponse;
        $decoded = $textresponse === '' ? [] : json_decode($textresponse, true);

        if ($httpcode < 200 || $httpcode >= 300) {
            $message = 'Server B returned an error.';
            if (is_array($decoded)) {
                if (isset($decoded['detail']) && is_array($decoded['detail'])) {
                    $message = (string) ($decoded['detail']['message'] ?? $decoded['detail']['code'] ?? $message);
                } else {
                    $message = (string) ($decoded['message'] ?? $decoded['error'] ?? $message);
                }
            } else {
                $message = clean_param($textresponse, PARAM_TEXT);
            }
            throw new \moodle_exception('error:serverresponse', 'local_proctorcore', '', $httpcode . ': ' . $message);
        }

        // A successful DELETE may intentionally return 204 No Content.
        if ($textresponse === '') {
            return ['success' => true, 'httpCode' => $httpcode];
        }
        if (!is_array($decoded)) {
            throw new \moodle_exception('error:invalidserverresponse', 'local_proctorcore');
        }

        return $decoded;
    }
}
