<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Development-only filesystem storage for Section 1.1 local capture testing.
 *
 * This adapter is deliberately separate from Server B. It lets developers test
 * browser camera/microphone recording and key-moment snapshots on one Moodle
 * server without removing the production Server B implementation.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class local_capture_storage {
    /** Maximum size accepted for one uploaded chunk or snapshot (25 MiB). */
    private const MAX_UPLOAD_BYTES = 26214400;

    /**
     * Whether development-only local capture mode is selected.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (string) get_config('local_proctorcore', 'capturemode') === 'localtest';
    }

    /**
     * Returns and optionally creates the configured private base directory.
     *
     * @param bool $create Create the directory when missing.
     * @return string Canonical absolute path.
     */
    public static function get_base_path(bool $create = true): string {
        global $CFG;

        $configured = trim((string) get_config('local_proctorcore', 'localstoragepath'));
        $path = $configured !== '' ? $configured : $CFG->dataroot . '/proctorcore_test';
        $path = rtrim($path, DIRECTORY_SEPARATOR);

        if ($path === '' || !self::is_absolute_path($path)) {
            throw new \moodle_exception('error:localstorageinvalid', 'local_proctorcore');
        }

        // Never permit test recordings inside the public Moodle source tree.
        $dirroot = realpath($CFG->dirroot) ?: $CFG->dirroot;
        $normalisedpath = self::normalise_path($path);
        $normaliseddirroot = self::normalise_path($dirroot);
        if ($normalisedpath === $normaliseddirroot
                || strpos($normalisedpath . '/', $normaliseddirroot . '/') === 0) {
            throw new \moodle_exception('error:localstorageinvalid', 'local_proctorcore');
        }

        if ($create) {
            make_writable_directory($path, true);
        }
        if (!is_dir($path) || !is_writable($path)) {
            throw new \moodle_exception('error:localstoragenotwritable', 'local_proctorcore');
        }

        return realpath($path) ?: $path;
    }

    /**
     * Returns a health result compatible with integration_service::health().
     *
     * @return array
     */
    public static function health(): array {
        try {
            $path = self::get_base_path(true);
            return [
                'success' => true,
                'status' => 'healthy',
                'mode' => 'localtest',
                'storagePath' => $path,
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'status' => 'unavailable',
                'mode' => 'localtest',
                'error' => clean_param($exception->getMessage(), PARAM_TEXT),
            ];
        }
    }

    /**
     * Saves one browser-uploaded video chunk or snapshot and registers an asset.
     *
     * @param \stdClass $session ProctorCore session.
     * @param array $upload One element from $_FILES.
     * @param string $kind video_chunk or snapshot.
     * @param string $reason Capture reason.
     * @param int $segment Recording segment number.
     * @param int $sequence Browser chunk sequence.
     * @param int|null $violationid Optional violation id.
     * @param int|null $actorid Current Moodle user id.
     * @return array
     */
    public function save_upload(
        \stdClass $session,
        array $upload,
        string $kind,
        string $reason,
        int $segment,
        int $sequence,
        ?int $violationid,
        ?int $actorid
    ): array {
        if (!self::is_enabled()) {
            throw new \moodle_exception('error:localstorageinvalid', 'local_proctorcore');
        }
        if (!in_array($kind, ['video_chunk', 'snapshot'], true)) {
            throw new \moodle_exception('error:localuploadtype', 'local_proctorcore');
        }
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
            throw new \moodle_exception('error:localuploadfailed', 'local_proctorcore');
        }

        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            throw new \moodle_exception('error:localuploadtoolarge', 'local_proctorcore');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($upload['tmp_name']);
        [$extension, $assettype, $subdirectory] = $this->resolve_type($kind, $reason, $mime);

        $basepath = self::get_base_path(true);
        $relativefolder = sprintf(
            'company_%d/session_%d/%s',
            (int) $session->companyid,
            (int) $session->id,
            $subdirectory
        );
        $targetfolder = $basepath . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativefolder);
        make_writable_directory($targetfolder, true);

        $timestamp = gmdate('Ymd_His');
        $random = bin2hex(random_bytes(4));
        if ($kind === 'video_chunk') {
            $filename = sprintf(
                'attempt_%d_segment_%03d_chunk_%06d_%s_%s.%s',
                (int) $session->attemptid,
                max(1, $segment),
                max(1, $sequence),
                $timestamp,
                $random,
                $extension
            );
        } else {
            $safeReason = clean_param($reason, PARAM_ALPHANUMEXT) ?: 'snapshot';
            $filename = sprintf(
                'attempt_%d_%s_%s_%s.%s',
                (int) $session->attemptid,
                $safeReason,
                $timestamp,
                $random,
                $extension
            );
        }

        $target = $targetfolder . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($upload['tmp_name'], $target)) {
            throw new \moodle_exception('error:localuploadfailed', 'local_proctorcore');
        }
        @chmod($target, 0660);

        $relativepath = $relativefolder . '/' . $filename;
        $externalid = sprintf(
            'local-%d-%s-%s',
            (int) $session->id,
            $kind === 'video_chunk' ? 'v' : 's',
            $random
        );
        $checksum = hash_file('sha256', $target);

        $asset = (new asset_repository())->create(
            (int) $session->id,
            (int) $session->companyid,
            $assettype,
            [
                'violationid' => $violationid,
                'storage' => 'external',
                'externalid' => $externalid,
                'checksum' => $checksum,
                'mime' => $mime,
                'filesize' => filesize($target),
                'availableat' => time(),
                'metadata' => [
                    'localTest' => true,
                    'relativePath' => $relativepath,
                    'kind' => $kind,
                    'reason' => $reason,
                    'segment' => max(1, $segment),
                    'sequence' => max(0, $sequence),
                ],
            ]
        );

        if ($kind === 'snapshot') {
            (new session_repository())->increment_snapshot_count((int) $session->id);
        }

        (new audit_logger())->log(
            'capture.local_asset_saved',
            (int) $session->companyid,
            (int) $session->id,
            (int) $session->userid,
            [
                'assetId' => (int) $asset->id,
                'assetType' => $assettype,
                'relativePath' => $relativepath,
                'reason' => $reason,
                'segment' => max(1, $segment),
                'sequence' => max(0, $sequence),
                'size' => filesize($target),
            ],
            $actorid,
            'asset',
            (int) $asset->id
        );

        return [
            'ok' => true,
            'assetId' => (int) $asset->id,
            'assetType' => $assettype,
            'externalId' => $externalid,
            'relativePath' => $relativepath,
            'mime' => $mime,
            'size' => filesize($target),
        ];
    }

    /**
     * Deletes a local-test asset referenced by its metadata.
     *
     * @param \stdClass $asset Asset row.
     * @return void
     */
    public function delete_asset(\stdClass $asset): void {
        $metadata = json_decode((string) ($asset->metadata ?? ''), true);
        if (!is_array($metadata) || empty($metadata['localTest']) || empty($metadata['relativePath'])) {
            throw new \coding_exception('The asset is not a local ProctorCore test asset.');
        }

        $basepath = self::get_base_path(false);
        $relative = str_replace(['\\', '..'], ['/', ''], (string) $metadata['relativePath']);
        $target = $basepath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/'));
        $normalisedbase = self::normalise_path($basepath);
        $normalisedtarget = self::normalise_path($target);
        if (strpos($normalisedtarget, $normalisedbase . '/') !== 0) {
            throw new \coding_exception('Refusing to delete a file outside the local ProctorCore test directory.');
        }
        if (is_file($target) && !unlink($target)) {
            throw new \moodle_exception('error:localuploadfailed', 'local_proctorcore');
        }
    }

    /**
     * Maps browser MIME type to extension and asset type.
     *
     * @param string $kind video_chunk or snapshot.
     * @param string $reason Capture reason.
     * @param string $mime Detected MIME type.
     * @return array [extension, asset type, subdirectory]
     */
    private function resolve_type(string $kind, string $reason, string $mime): array {
        if ($kind === 'video_chunk') {
            if (!in_array($mime, ['video/webm', 'application/octet-stream'], true)) {
                throw new \moodle_exception('error:localuploadtype', 'local_proctorcore');
            }
            return ['webm', asset_repository::TYPE_VIDEO_CLIP, 'video'];
        }

        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($extensions[$mime])) {
            throw new \moodle_exception('error:localuploadtype', 'local_proctorcore');
        }
        $assettype = $reason === 'identity_verification'
            ? asset_repository::TYPE_IDENTITY_PHOTO
            : asset_repository::TYPE_SNAPSHOT;
        return [$extensions[$mime], $assettype, 'snapshots'];
    }

    /** @return bool */
    private static function is_absolute_path(string $path): bool {
        return $path[0] === '/' || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    /** @return string */
    private static function normalise_path(string $path): string {
        $path = str_replace('\\', '/', $path);
        return rtrim(preg_replace('#/+#', '/', $path), '/');
    }
}
