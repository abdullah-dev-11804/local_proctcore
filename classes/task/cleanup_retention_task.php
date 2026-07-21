<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\task;

<<<<<<< HEAD
defined('MOODLE_INTERNAL') || die();

/**
 * Clears expired proctoring evidence references after retention periods end.
 */
final class cleanup_retention_task extends \core\task\scheduled_task {
=======
use local_proctorcore\local\asset_repository;
use local_proctorcore\local\audit_logger;
use local_proctorcore\local\local_capture_storage;
use local_proctorcore\local\server_client;

defined('MOODLE_INTERNAL') || die();

/**
 * Permanently removes expired proctoring evidence from its storage backend.
 *
 * Asset database rows are retained as immutable evidence that cleanup occurred;
 * only the underlying Server B object or Moodle file is removed.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cleanup_retention_task extends \core\task\scheduled_task {
    /** Maximum assets deleted in one cron execution. */
    private const BATCH_SIZE = 500;

    /**
     * Task display name.
     *
     * @return string
     */
>>>>>>> origin/danial
    public function get_name(): string {
        return get_string('task:cleanup_retention', 'local_proctorcore');
    }

<<<<<<< HEAD
    public function execute(): void {
        // Workflow placeholder for retention cleanup and Server B deletion calls.
=======
    /**
     * Deletes expired non-held evidence and soft-deletes its local reference.
     *
     * A failed remote deletion is deliberately left active so the next cron run
     * can retry it. An appeal/evidence hold always wins over an expiry date.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $assets = new asset_repository();
        $audit = new audit_logger();
        $expired = $assets->get_expired(time(), self::BATCH_SIZE);

        if (!$expired) {
            mtrace('ProctorCore retention cleanup: no expired evidence.');
            return;
        }

        $deleted = 0;
        $failed = 0;
        foreach ($expired as $asset) {
            try {
                // Re-check the row immediately before deletion. An appeal may
                // have placed a hold after this batch was selected.
                $current = $assets->get_by_id((int) $asset->id, IGNORE_MISSING);
                if (!$current || (string) $current->status !== 'active' || !empty($current->isheld)
                        || empty($current->expiresat) || (int) $current->expiresat > time()) {
                    continue;
                }

                $this->delete_underlying_asset($current);
                $assets->mark_deleted((int) $current->id);

                $session = $DB->get_record(
                    'local_proctorcore_sessions',
                    ['id' => (int) $current->sessionid],
                    'id,userid',
                    IGNORE_MISSING
                );
                $audit->log(
                    'retention.asset_deleted',
                    (int) $current->companyid,
                    (int) $current->sessionid,
                    $session ? (int) $session->userid : null,
                    [
                        'assetType' => (string) $current->assettype,
                        'storage' => (string) $current->storage,
                        'externalId' => (string) ($current->externalid ?? ''),
                        'expiredAt' => (int) $current->expiresat,
                        'deletedAt' => time(),
                    ],
                    null,
                    'asset',
                    (int) $current->id
                );

                $deleted++;
                mtrace('ProctorCore deleted expired asset ' . (int) $current->id
                    . ' (' . (string) $current->assettype . ').');
            } catch (\Throwable $exception) {
                $failed++;
                mtrace('ProctorCore could not delete asset ' . (int) $asset->id
                    . ': ' . clean_param($exception->getMessage(), PARAM_TEXT));

                try {
                    $audit->log(
                        'retention.asset_delete_failed',
                        (int) $asset->companyid,
                        (int) $asset->sessionid,
                        null,
                        [
                            'assetType' => (string) $asset->assettype,
                            'storage' => (string) $asset->storage,
                            'externalId' => (string) ($asset->externalid ?? ''),
                            'error' => clean_param($exception->getMessage(), PARAM_TEXT),
                        ],
                        null,
                        'asset',
                        (int) $asset->id
                    );
                } catch (\Throwable $auditexception) {
                    debugging(
                        'ProctorCore could not audit retention failure: ' . $auditexception->getMessage(),
                        DEBUG_DEVELOPER
                    );
                }
            }
        }

        mtrace('ProctorCore retention cleanup finished: ' . $deleted . ' deleted, '
            . $failed . ' failed.');
    }

    /**
     * Deletes one asset from its configured storage backend.
     *
     * `external` is treated as a Server B-managed external object. If a future
     * backend is added, introduce an explicit adapter rather than deleting a URL.
     *
     * @param \stdClass $asset Asset row.
     * @return void
     */
    private function delete_underlying_asset(\stdClass $asset): void {
        switch ((string) $asset->storage) {
            case 'server_b':
            case 'external':
                $metadata = json_decode((string) ($asset->metadata ?? ''), true);
                if ((string) $asset->storage === 'external' && is_array($metadata)
                        && !empty($metadata['localTest'])) {
                    (new local_capture_storage())->delete_asset($asset);
                    return;
                }
                if (empty($asset->externalid)) {
                    throw new \coding_exception(
                        'A remote ProctorCore asset cannot be deleted without externalid.'
                    );
                }
                (new server_client((int) $asset->companyid))->delete_asset(
                    (string) $asset->externalid,
                    [
                        'moodleAssetId' => (int) $asset->id,
                        'moodleSessionId' => (int) $asset->sessionid,
                        'companyId' => (int) $asset->companyid,
                        'reason' => 'retention_expired',
                        'requestedAt' => gmdate('c'),
                        'idempotencyKey' => 'asset-delete-' . (int) $asset->id,
                    ]
                );
                return;

            case 'moodle_file':
                if (empty($asset->filearea) || $asset->itemid === null) {
                    throw new \coding_exception(
                        'A Moodle file asset requires filearea and itemid for retention cleanup.'
                    );
                }
                get_file_storage()->delete_area_files(
                    \context_system::instance()->id,
                    'local_proctorcore',
                    (string) $asset->filearea,
                    (int) $asset->itemid
                );
                return;

            default:
                throw new \coding_exception(
                    'Unsupported ProctorCore asset storage backend: ' . (string) $asset->storage
                );
        }
>>>>>>> origin/danial
    }
}
