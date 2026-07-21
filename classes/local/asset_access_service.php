<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves and serves protected report evidence.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class asset_access_service {
    /** Maximum remote image size embedded into a generated PDF. */
    private const MAX_PDF_IMAGE_BYTES = 8388608;

    /** Maximum protected clip size proxied through Moodle (256 MiB). */
    private const MAX_REMOTE_ASSET_BYTES = 268435456;

    /**
     * Serves or redirects one authorised asset.
     *
     * The caller must perform the session permission check first.
     *
     * @param \stdClass $asset Asset row.
     * @param bool $forcedownload Force attachment download.
     * @return never
     */
    public function serve(\stdClass $asset, bool $forcedownload = false): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if ((string) $asset->status !== 'active' || !empty($asset->deletedat)
                || (!empty($asset->expiresat) && (int) $asset->expiresat <= time() && empty($asset->isheld))) {
            throw new \moodle_exception('error:assetunavailable', 'local_proctorcore');
        }

        if ((string) $asset->storage === 'moodle_file') {
            $file = $this->get_moodle_file($asset);
            send_stored_file($file, 0, 0, $forcedownload);
        }

        $metadata = $this->metadata($asset);
        if ((string) $asset->storage === 'external' && !empty($metadata['localTest'])) {
            $path = $this->get_local_test_path($asset);
            $filename = clean_param(basename($path), PARAM_FILE);
            send_file(
                $path,
                $filename,
                0,
                0,
                false,
                $forcedownload,
                (string) ($asset->mime ?: 'application/octet-stream')
            );
        }

        // Server B evidence remains private. Moodle fetches the object with its
        // server-side API credential and streams it only after the caller has
        // passed the report/session permission check.
        if (in_array((string) $asset->storage, ['server_b', 'external'], true)
                && !empty($asset->externalid)) {
            $content = $this->fetch_remote_asset($asset, self::MAX_REMOTE_ASSET_BYTES);
            if ($content === null) {
                throw new \moodle_exception('error:assetunavailable', 'local_proctorcore');
            }
            $tempdir = make_request_directory();
            $filename = $this->asset_filename($asset);
            $path = $tempdir . DIRECTORY_SEPARATOR . $filename;
            if (file_put_contents($path, $content, LOCK_EX) === false) {
                throw new \moodle_exception('error:assetunavailable', 'local_proctorcore');
            }
            send_file(
                $path,
                $filename,
                0,
                0,
                false,
                $forcedownload,
                (string) ($asset->mime ?: 'application/octet-stream')
            );
        }

        $url = $this->get_remote_url($asset);
        redirect($url);
    }

    /**
     * Gets image bytes for PDF embedding.
     *
     * @param \stdClass $asset Image asset.
     * @return string|null
     */
    public function get_image_content(\stdClass $asset): ?string {
        if (strpos((string) $asset->mime, 'image/') !== 0) {
            return null;
        }

        if ((string) $asset->storage === 'moodle_file') {
            $file = $this->get_moodle_file($asset);
            if ($file->get_filesize() > self::MAX_PDF_IMAGE_BYTES) {
                return null;
            }
            return $file->get_content();
        }

        $metadata = $this->metadata($asset);
        if ((string) $asset->storage === 'external' && !empty($metadata['localTest'])) {
            $path = $this->get_local_test_path($asset);
            if (!is_file($path) || filesize($path) > self::MAX_PDF_IMAGE_BYTES) {
                return null;
            }
            return file_get_contents($path) ?: null;
        }

        return $this->fetch_remote_asset($asset, self::MAX_PDF_IMAGE_BYTES);
    }

    /**
     * Gets a generated Moodle report file.
     *
     * @param \stdClass $asset Report asset.
     * @return \stored_file
     */
    public function get_moodle_file(\stdClass $asset): \stored_file {
        $metadata = $this->metadata($asset);
        $contextid = isset($metadata['contextid'])
            ? (int) $metadata['contextid']
            : \context_system::instance()->id;
        $filename = clean_param((string) ($metadata['filename'] ?? ''), PARAM_FILE);
        if ($filename === '') {
            $filename = 'proctorcore-report-' . (int) $asset->sessionid . '.pdf';
        }
        $filearea = (string) ($asset->filearea ?: 'reports');
        $itemid = $asset->itemid !== null ? (int) $asset->itemid : (int) $asset->sessionid;
        $file = get_file_storage()->get_file(
            $contextid,
            'local_proctorcore',
            $filearea,
            $itemid,
            '/',
            $filename
        );
        if (!$file || $file->is_directory()) {
            throw new \moodle_exception('error:assetunavailable', 'local_proctorcore');
        }
        return $file;
    }

    /**
     * Resolves a local development asset path safely.
     *
     * @param \stdClass $asset Asset row.
     * @return string
     */
    private function get_local_test_path(\stdClass $asset): string {
        $metadata = $this->metadata($asset);
        if (empty($metadata['relativePath'])) {
            throw new \moodle_exception('error:assetunavailable', 'local_proctorcore');
        }
        $base = local_capture_storage::get_base_path(false);
        $relative = str_replace(['\\', '..'], ['/', ''], (string) $metadata['relativePath']);
        $target = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/'));
        $normalbase = $this->normalise_path($base);
        $normaltarget = $this->normalise_path($target);
        if (strpos($normaltarget, $normalbase . '/') !== 0 || !is_file($target)) {
            throw new \moodle_exception('error:assetunavailable', 'local_proctorcore');
        }
        return $target;
    }

    /**
     * Gets and validates a Server B evidence URL.
     *
     * Server B should return a short-lived signed endpoint on its own configured origin.
     *
     * @param \stdClass $asset Asset row.
     * @return \moodle_url
     */
    private function get_remote_url(\stdClass $asset): \moodle_url {
        $config = (new company_config_repository())->require_enabled_config((int) $asset->companyid);
        $base = rtrim((string) $config->serverbaseurl, '/');
        $raw = trim((string) ($asset->url ?? ''));
        if ($raw === '') {
            throw new \moodle_exception('error:assetunavailable', 'local_proctorcore');
        }
        $url = strpos($raw, '/') === 0 ? $base . $raw : $raw;
        $baseparts = parse_url($base);
        $urlparts = parse_url($url);
        if (!$baseparts || !$urlparts
                || strtolower((string) ($baseparts['scheme'] ?? '')) !== strtolower((string) ($urlparts['scheme'] ?? ''))
                || strtolower((string) ($baseparts['host'] ?? '')) !== strtolower((string) ($urlparts['host'] ?? ''))
                || (int) ($baseparts['port'] ?? $this->default_port((string) ($baseparts['scheme'] ?? '')))
                    !== (int) ($urlparts['port'] ?? $this->default_port((string) ($urlparts['scheme'] ?? '')))) {
            throw new \moodle_exception('error:asseturlblocked', 'local_proctorcore');
        }
        return new \moodle_url($url);
    }

    /**
     * Fetches one private Server B asset using Moodle's server-side API key.
     *
     * Server B must provide GET /api/v1/assets/{externalId}/content and must
     * require the same Bearer API key configured for the company. This keeps
     * private loopback/private-network storage inaccessible to the browser.
     *
     * @param \stdClass $asset Asset row.
     * @param int $maxbytes Maximum accepted response size.
     * @return string|null
     */
    private function fetch_remote_asset(\stdClass $asset, int $maxbytes): ?string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if (empty($asset->externalid)) {
            return null;
        }

        try {
            $config = (new company_config_repository())->require_enabled_config((int) $asset->companyid);
            $url = rtrim((string) $config->serverbaseurl, '/')
                . '/api/v1/assets/' . rawurlencode((string) $asset->externalid) . '/content';
            $curl = new \curl();
            $headers = [
                'Accept: ' . (string) ($asset->mime ?: 'application/octet-stream'),
                'X-ProctorCore-Company: ' . (int) $asset->companyid,
                'X-ProctorCore-Source: moodle-local-proctorcore',
            ];
            if ($config->serverapikey !== '') {
                $headers[] = 'Authorization: Bearer ' . $config->serverapikey;
            }
            $curl->setHeader($headers);
            $content = $curl->get($url, [], [
                'CURLOPT_CONNECTTIMEOUT' => (int) $config->connecttimeout,
                'CURLOPT_TIMEOUT' => max((int) $config->requesttimeout, 60),
                'CURLOPT_SSL_VERIFYPEER' => (bool) $config->verifyssl,
                'CURLOPT_SSL_VERIFYHOST' => $config->verifyssl ? 2 : 0,
            ]);
            $info = $curl->get_info();
            $httpcode = (int) ($info['http_code'] ?? 0);
            if ($httpcode < 200 || $httpcode >= 300) {
                return null;
            }
            $content = (string) $content;
            if ($content === '' || strlen($content) > $maxbytes) {
                return null;
            }
            return $content;
        } catch (\Throwable $exception) {
            debugging('ProctorCore could not fetch protected evidence: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Builds a safe evidence filename.
     *
     * @param \stdClass $asset Asset row.
     * @return string
     */
    private function asset_filename(\stdClass $asset): string {
        $metadata = $this->metadata($asset);
        foreach (['filename', 'fileName', 'relativePath', 'path'] as $key) {
            if (!empty($metadata[$key])) {
                $filename = clean_param(basename((string) $metadata[$key]), PARAM_FILE);
                if ($filename !== '') {
                    return $filename;
                }
            }
        }
        $extension = mimeinfo('extension', (string) ($asset->mime ?: 'application/octet-stream'));
        $extension = $extension === '???' ? '' : clean_param((string) $extension, PARAM_ALPHANUM);
        $base = clean_param((string) ($asset->externalid ?: ('asset-' . (int) $asset->id)), PARAM_FILE);
        return $base . ($extension ? '.' . $extension : '');
    }

    /** @param \stdClass $asset @return array */
    private function metadata(\stdClass $asset): array {
        $metadata = json_decode((string) ($asset->metadata ?? ''), true);
        return is_array($metadata) ? $metadata : [];
    }

    /** @param string $scheme @return int */
    private function default_port(string $scheme): int {
        return strtolower($scheme) === 'https' ? 443 : 80;
    }

    /** @param string $path @return string */
    private function normalise_path(string $path): string {
        return rtrim(preg_replace('#/+#', '/', str_replace('\\', '/', $path)), '/');
    }
}
