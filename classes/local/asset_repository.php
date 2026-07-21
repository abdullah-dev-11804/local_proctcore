<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Owns report, video, snapshot, room scan, ID photo, and violation-act references.
<<<<<<< HEAD
 */
final class asset_repository {
    public const TABLE = 'local_proctorcore_assets';
=======
 *
 * Section 1.1: the browser/Server B side performs the actual camera and
 * microphone capture. This repository stores what Moodle needs to know
 * about each resulting file — where it lives, its type, and when it must
 * be deleted — so retention (Sections 1.1 / 3.2) and appeals (Section 8.1)
 * can act on it without talking to Server B for every check.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class asset_repository {
    /** Assets table. */
    public const TABLE = 'local_proctorcore_assets';

    /** Key moment / violation photo. Report snapshots, 6-month retention. */
    public const TYPE_SNAPSHOT = 'snapshot';

    /** Video clip of a key moment. Appeal-period retention. */
    public const TYPE_VIDEO_CLIP = 'video_clip';

    /** Generated PDF proctoring report. 6-month retention. */
    public const TYPE_REPORT = 'report';

    /** Identity check face/ID photo. */
    public const TYPE_IDENTITY_PHOTO = 'identity_photo';

    /** 360-degree room scan capture (Section 1.4, future). */
    public const TYPE_ROOM_SCAN = 'room_scan';

    /** Generated violation/incident act document. */
    public const TYPE_VIOLATION_ACT = 'violation_act';

    /** Valid values for the assettype column. */
    private const VALID_TYPES = [
        self::TYPE_SNAPSHOT,
        self::TYPE_VIDEO_CLIP,
        self::TYPE_REPORT,
        self::TYPE_IDENTITY_PHOTO,
        self::TYPE_ROOM_SCAN,
        self::TYPE_VIOLATION_ACT,
    ];

    /** Valid values for the storage column. */
    private const VALID_STORAGE = ['server_b', 'moodle_file', 'external'];

    /** @var retention_policy */
    private $retention;

    /**
     * Constructor.
     *
     * @param retention_policy|null $retention Optional dependency.
     */
    public function __construct(?retention_policy $retention = null) {
        $this->retention = $retention ?? new retention_policy();
    }

    /**
     * Registers a new asset reference and computes its retention expiry.
     *
     * Expiry is set automatically from {@see retention_policy} based on
     * assettype unless the caller passes an explicit expiresat override.
     *
     * @param int $sessionid Local ProctorCore session id.
     * @param int $companyid IOMAD company id.
     * @param string $assettype One of the TYPE_* constants.
     * @param array $data Optional fields: violationid, storage, externalid,
     *                     url, filearea, itemid, checksum, mime, filesize,
     *                     availableat, expiresat, metadata, completedat.
     * @return \stdClass Inserted asset record.
     */
    public function create(int $sessionid, int $companyid, string $assettype, array $data = []): \stdClass {
        global $DB;

        if (!in_array($assettype, self::VALID_TYPES, true)) {
            throw new \moodle_exception('error:invalidassettype', 'local_proctorcore', '', $assettype);
        }

        $storage = (string) ($data['storage'] ?? 'server_b');
        if (!in_array($storage, self::VALID_STORAGE, true)) {
            throw new \moodle_exception('error:invalidassetstorage', 'local_proctorcore', '', $storage);
        }

        $completedat = isset($data['completedat']) ? (int) $data['completedat'] : 0;
        if (array_key_exists('expiresat', $data)) {
            $expiresat = $data['expiresat'];
        } else if ($completedat > 0) {
            $expiresat = $this->default_expiry($assettype, $companyid, $completedat);
        } else {
            // Media can arrive before the Quiz is submitted. Its retention clock
            // starts from final session completion, not from snapshot/clip time.
            $session = $DB->get_record('local_proctorcore_sessions', ['id' => $sessionid],
                'id,endedat,reportexpiresat,videoexpiresat', MUST_EXIST);
            if (!empty($session->endedat)) {
                $expiresat = in_array($assettype, [self::TYPE_VIDEO_CLIP, self::TYPE_ROOM_SCAN], true)
                    ? (int) $session->videoexpiresat
                    : (int) $session->reportexpiresat;
            } else {
                $expiresat = null;
            }
        }

        $metadata = $data['metadata'] ?? null;
        $encodedmetadata = null;
        if ($metadata !== null) {
            $encodedmetadata = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encodedmetadata === false) {
                throw new \coding_exception('Unable to encode ProctorCore asset metadata.');
            }
        }

        $now = time();
        $record = (object) [
            'sessionid' => $sessionid,
            'companyid' => $companyid,
            'violationid' => isset($data['violationid']) ? (int) $data['violationid'] : null,
            'assettype' => $assettype,
            'storage' => $storage,
            'status' => 'active',
            'externalid' => isset($data['externalid']) ? clean_param((string) $data['externalid'], PARAM_TEXT) : null,
            'url' => isset($data['url']) ? clean_param((string) $data['url'], PARAM_URL) : null,
            'filearea' => isset($data['filearea']) ? clean_param((string) $data['filearea'], PARAM_ALPHANUMEXT) : null,
            'itemid' => isset($data['itemid']) ? (int) $data['itemid'] : null,
            'checksum' => isset($data['checksum']) ? clean_param((string) $data['checksum'], PARAM_ALPHANUM) : null,
            'mime' => isset($data['mime']) ? clean_param((string) $data['mime'], PARAM_TEXT) : null,
            'filesize' => isset($data['filesize']) ? (int) $data['filesize'] : null,
            'isheld' => 0,
            'availableat' => isset($data['availableat']) ? (int) $data['availableat'] : $now,
            'expiresat' => $expiresat !== null ? (int) $expiresat : null,
            'deletedat' => null,
            'metadata' => $encodedmetadata,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $record->id = (int) $DB->insert_record(self::TABLE, $record);
        return $record;
    }

    /**
     * Returns all assets for a session, optionally filtered by type.
     *
     * @param int $sessionid Local session id.
     * @param string|null $assettype Optional TYPE_* filter.
     * @return \stdClass[]
     */
    public function get_for_session(int $sessionid, ?string $assettype = null): array {
        global $DB;

        $params = ['sessionid' => $sessionid];
        if ($assettype !== null) {
            $params['assettype'] = $assettype;
        }

        return $DB->get_records(self::TABLE, $params, 'timecreated ASC');
    }

    /**
     * Fetches a single asset by id.
     *
     * @param int $id Asset row id.
     * @param int $strictness MUST_EXIST or IGNORE_MISSING.
     * @return \stdClass|false
     */
    public function get_by_id(int $id, int $strictness = MUST_EXIST) {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id], '*', $strictness);
    }

    /**
     * Finds an asset by its Server B external id, if already recorded.
     *
     * Lets webhook handlers stay idempotent when Server B retries an
     * asset-captured event.
     *
     * @param string $externalid Server B asset id.
     * @return \stdClass|null
     */
    public function get_by_external_id(
        string $externalid,
        ?int $companyid = null,
        ?int $sessionid = null
    ): ?\stdClass {
        global $DB;

        $conditions = ['externalid' => $externalid];
        if ($companyid !== null) {
            $conditions['companyid'] = $companyid;
        }
        if ($sessionid !== null) {
            $conditions['sessionid'] = $sessionid;
        }
        $record = $DB->get_record(self::TABLE, $conditions, '*', IGNORE_MISSING);
        return $record ?: null;
    }

    /**
     * Places an evidence hold on an asset, exempting it from retention cleanup.
     *
     * Used when an appeal is filed (Section 8.1) and the recording must be
     * kept beyond its originally computed expiry until the appeal, and any
     * subsequent course completion, resolves.
     *
     * @param int $id Asset id.
     * @return void
     */
    public function mark_held(int $id): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'isheld' => 1,
            'timemodified' => time(),
        ]);
    }

    /**
     * Releases an evidence hold and sets a new expiry.
     *
     * @param int $id Asset id.
     * @param int $expiresat New expiry timestamp, typically from
     *                        {@see retention_policy::extend_for_course_completion()}.
     * @return void
     */
    public function release_hold(int $id, int $expiresat): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'isheld' => 0,
            'expiresat' => $expiresat,
            'timemodified' => time(),
        ]);
    }

    /**
     * Applies final session retention timestamps to all active assets.
     *
     * @param int $sessionid Local session id.
     * @param int $reportexpiresat Snapshot/report expiry.
     * @param int $videoexpiresat Video/room-scan expiry.
     * @return void
     */
    public function apply_session_retention(
        int $sessionid,
        int $reportexpiresat,
        int $videoexpiresat
    ): void {
        global $DB;

        $videoassets = [self::TYPE_VIDEO_CLIP, self::TYPE_ROOM_SCAN];
        list($insql, $params) = $DB->get_in_or_equal($videoassets, SQL_PARAMS_NAMED, 'videoasset');
        $params['sessionid'] = $sessionid;
        $params['active'] = 'active';
        $params['videoexpiry'] = $videoexpiresat;
        $params['reportexpiry'] = $reportexpiresat;
        $params['now'] = time();

        $sql = "UPDATE {" . self::TABLE . "}
                   SET expiresat = CASE WHEN assettype $insql THEN :videoexpiry ELSE :reportexpiry END,
                       timemodified = :now
                 WHERE sessionid = :sessionid
                   AND status = :active";
        $DB->execute($sql, $params);
    }

    /**
     * Returns active, non-held assets whose retention period has passed.
     *
     * @param int $before Cutoff timestamp, normally time().
     * @param int $limit Maximum rows to return per run.
     * @return \stdClass[]
     */
    public function get_expired(int $before, int $limit = 200): array {
        global $DB;

        $sql = "SELECT *
                  FROM {" . self::TABLE . "}
                 WHERE status = :status
                   AND isheld = 0
                   AND expiresat IS NOT NULL
                   AND expiresat <= :before
              ORDER BY expiresat ASC";

        return $DB->get_records_sql($sql, ['status' => 'active', 'before' => $before], 0, $limit);
    }

    /**
     * Marks an asset deleted after its Server B / Moodle file copy is removed.
     *
     * This is a soft delete: the row and its audit trail remain, only the
     * underlying media is gone.
     *
     * @param int $id Asset id.
     * @return void
     */
    public function mark_deleted(int $id): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $id,
            'status' => 'deleted',
            'deletedat' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Returns the active Moodle-generated PDF report for a session.
     *
     * @param int $sessionid Session id.
     * @return \stdClass|null
     */
    public function get_generated_report(int $sessionid): ?\stdClass {
        global $DB;

        $sql = "SELECT *
                  FROM {" . self::TABLE . "}
                 WHERE sessionid = :sessionid
                   AND assettype = :assettype
                   AND storage = :storage
                   AND status = :status
                   AND deletedat IS NULL
              ORDER BY id DESC";
        $record = $DB->get_record_sql($sql, [
            'sessionid' => $sessionid,
            'assettype' => self::TYPE_REPORT,
            'storage' => 'moodle_file',
            'status' => 'active',
        ], IGNORE_MULTIPLE);
        return $record ?: null;
    }

    /**
     * Creates or updates the generated Moodle PDF report asset.
     *
     * @param int $sessionid Session id.
     * @param int $companyid Company id.
     * @param \stored_file $file Stored PDF file.
     * @param int $expiresat Retention expiry.
     * @param array $metadata Report metadata.
     * @return \stdClass
     */
    public function upsert_generated_report(
        int $sessionid,
        int $companyid,
        \stored_file $file,
        int $expiresat,
        array $metadata
    ): \stdClass {
        global $DB;

        $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \coding_exception('Unable to encode generated report metadata.');
        }
        $existing = $this->get_generated_report($sessionid);
        $now = time();
        if ($existing) {
            $DB->update_record(self::TABLE, (object) [
                'id' => (int) $existing->id,
                'companyid' => $companyid,
                'storage' => 'moodle_file',
                'status' => 'active',
                'externalid' => 'moodle-report-' . $sessionid,
                'url' => null,
                'filearea' => 'reports',
                'itemid' => $sessionid,
                'checksum' => $file->get_contenthash(),
                'mime' => 'application/pdf',
                'filesize' => $file->get_filesize(),
                'availableat' => $now,
                'expiresat' => $expiresat,
                'deletedat' => null,
                'metadata' => $encoded,
                'timemodified' => $now,
            ]);
            return $this->get_by_id((int) $existing->id);
        }

        return $this->create($sessionid, $companyid, self::TYPE_REPORT, [
            'storage' => 'moodle_file',
            'externalid' => 'moodle-report-' . $sessionid,
            'filearea' => 'reports',
            'itemid' => $sessionid,
            'checksum' => $file->get_contenthash(),
            'mime' => 'application/pdf',
            'filesize' => $file->get_filesize(),
            'availableat' => $now,
            'expiresat' => $expiresat,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Computes the default expiry for a newly created asset by type.
     *
     * @param string $assettype One of the TYPE_* constants.
     * @param int $companyid IOMAD company id.
     * @param int $completedat Reference time (session completion, or now).
     * @return int|null
     */
    private function default_expiry(string $assettype, int $companyid, int $completedat): ?int {
        switch ($assettype) {
            case self::TYPE_VIDEO_CLIP:
            case self::TYPE_ROOM_SCAN:
                return $this->retention->compute_video_expiry($companyid, $completedat);
            case self::TYPE_REPORT:
            case self::TYPE_SNAPSHOT:
            case self::TYPE_IDENTITY_PHOTO:
            case self::TYPE_VIOLATION_ACT:
                return $this->retention->compute_report_expiry($companyid, $completedat);
            default:
                return null;
        }
    }
>>>>>>> origin/danial
}
