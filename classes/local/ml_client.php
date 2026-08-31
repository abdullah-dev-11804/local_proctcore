<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * JSON client for the ProctorCore ML identity and behaviour-analysis service.
 *
 * The service is deliberately separate from Moodle PHP so model inference can
 * use Python/OpenCV and can be scaled independently later.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ml_client {
    /** @var \stdClass */
    private $config;

    /** @var int */
    private $companyid;

    /**
     * @param int $companyid IOMAD company id.
     * @param company_config_repository|null $configs Optional configuration repository.
     */
    public function __construct(int $companyid, ?company_config_repository $configs = null) {
        $repository = $configs ?? new company_config_repository();
        $this->config = $repository->get_effective_config($companyid);
        $this->companyid = max(0, $companyid);
    }

    /** @return array */
    public function health(): array {
        return $this->request('GET', '/health');
    }

    /**
     * Verifies identity and the simple centre/left/right liveness challenge.
     *
     * @param string $referencebytes Moodle profile image bytes.
     * @param string $centerbytes Straight-looking camera image bytes.
     * @param string $leftbytes Left-turn challenge image bytes.
     * @param string $rightbytes Right-turn challenge image bytes.
     * @param string $transactionid Correlation id.
     * @return array
     */
    public function verify_identity(
        string $referencebytes,
        string $centerbytes,
        string $leftbytes,
        string $rightbytes,
        string $transactionid
    ): array {
        return $this->request('POST', '/api/v1/identity/verify', [
            'transactionId' => $transactionid,
            'companyId' => $this->companyid,
            'threshold' => (float) $this->config->identitythreshold,
            'referenceImage' => base64_encode($referencebytes),
            'centerImage' => base64_encode($centerbytes),
            'leftImage' => base64_encode($leftbytes),
            'rightImage' => base64_encode($rightbytes),
        ]);
    }

    /**
     * Analyses one exam frame.
     *
     * @param string $framebytes JPEG/PNG bytes.
     * @param string|null $referencebytes Optional profile image for periodic re-verification.
     * @param string $sessionkey External or local session key.
     * @return array
     */
    public function analyse_frame(
        string $framebytes,
        ?string $referencebytes,
        string $sessionkey
    ): array {
        $payload = [
            'sessionId' => $sessionkey,
            'companyId' => $this->companyid,
            'threshold' => (float) $this->config->identitythreshold,
            'frameImage' => base64_encode($framebytes),
        ];
        if ($referencebytes !== null && $referencebytes !== '') {
            $payload['referenceImage'] = base64_encode($referencebytes);
        }
        return $this->request('POST', '/api/v1/monitor/analyse', $payload);
    }

    /**
     * @param string $method HTTP method.
     * @param string $path Relative service path.
     * @param array|null $payload Optional JSON payload.
     * @return array
     */
    private function request(string $method, string $path, ?array $payload = null): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $baseurl = rtrim((string) $this->config->mlserviceurl, '/');
        if ($baseurl === '') {
            throw new \moodle_exception('error:mlurlmissing', 'local_proctorcore');
        }
        if (!preg_match('~^https://~i', $baseurl) && !empty($this->config->mlverifyssl)) {
            throw new \moodle_exception('error:mlhttpsrequired', 'local_proctorcore');
        }

        $curl = new \curl();
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-ProctorCore-Company: ' . $this->companyid,
            'X-ProctorCore-Source: moodle-local-proctorcore',
        ];
        if ((string) $this->config->mlapikey !== '') {
            $headers[] = 'Authorization: Bearer ' . (string) $this->config->mlapikey;
        }
        $curl->setHeader($headers);

        $options = [
            'CURLOPT_CONNECTTIMEOUT' => (int) $this->config->mlconnecttimeout,
            'CURLOPT_TIMEOUT' => (int) $this->config->mlrequesttimeout,
            'CURLOPT_SSL_VERIFYPEER' => !empty($this->config->mlverifyssl),
            'CURLOPT_SSL_VERIFYHOST' => !empty($this->config->mlverifyssl) ? 2 : 0,
        ];

        $url = $baseurl . '/' . ltrim($path, '/');
        $body = $payload === null ? '' : json_encode($payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload !== null && $body === false) {
            throw new \coding_exception('Unable to encode ProctorCore ML payload.');
        }

        try {
            $raw = strtoupper($method) === 'GET'
                ? $curl->get($url, [], $options)
                : $curl->post($url, $body, $options);
        } catch (\Throwable $exception) {
            throw new \moodle_exception('error:mlunavailable', 'local_proctorcore', '', null,
                $exception->getMessage());
        }

        $info = $curl->get_info();
        $httpcode = (int) ($info['http_code'] ?? 0);
        $decoded = json_decode((string) $raw, true);
        if ($httpcode < 200 || $httpcode >= 300) {
            $message = is_array($decoded)
                ? (string) ($decoded['message'] ?? $decoded['error'] ?? 'ML service error')
                : clean_param((string) $raw, PARAM_TEXT);
            throw new \moodle_exception('error:mlresponse', 'local_proctorcore', '',
                $httpcode . ': ' . $message);
        }
        if (!is_array($decoded)) {
            throw new \moodle_exception('error:invalidmlresponse', 'local_proctorcore');
        }
        return $decoded;
    }
}
