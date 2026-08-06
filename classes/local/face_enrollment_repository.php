<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Repository for one reusable face reference per Moodle user.
 *
 * The image itself is stored on Server B. Moodle stores the official
 * confirmation, Server B reference id/key, reset history, and audit metadata.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class face_enrollment_repository {
    /** Table name. */
    public const TABLE = 'local_proctorcore_faceenrol';

    /** @return \stdClass|null */
    public function get_active(int $userid): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['userid' => $userid, 'status' => 'active']);
        return $record ?: null;
    }

    /** @return bool */
    public function has_active(int $userid): bool {
        return $this->get_active($userid) !== null;
    }

    /**
     * Creates or replaces the active enrollment for a user.
     *
     * @param int $userid Moodle user id.
     * @param int $companyid IOMAD company id at enrollment time.
     * @param string $serverreferenceid Stable Server B reference id.
     * @param string $referencekey Server B storage key/path.
     * @param string $confirmedname Name shown to and confirmed by the user.
     * @param int $confirmedat Confirmation timestamp.
     * @param array $quality Quality metadata returned by Server B.
     * @param array $servermetadata Full Server B enrollment response metadata.
     * @param int $usermodified Actor user id.
     * @return \stdClass
     */
    public function upsert_active(
        int $userid,
        int $companyid,
        string $serverreferenceid,
        string $referencekey,
        string $confirmedname,
        int $confirmedat,
        array $quality,
        array $servermetadata,
        int $usermodified
    ): \stdClass {
        global $DB;

        $now = time();
        $existing = $DB->get_record(self::TABLE, ['userid' => $userid]);
        $record = (object) [
            'userid' => $userid,
            'companyid' => max(0, $companyid),
            'status' => 'active',
            'server_referenceid' => \core_text::substr(clean_param($serverreferenceid, PARAM_TEXT), 0, 128),
            'referencekey' => clean_param($referencekey, PARAM_TEXT),
            'confirmedname' => \core_text::substr(clean_param($confirmedname, PARAM_TEXT), 0, 255),
            'confirmedat' => max(1, $confirmedat),
            'enrolledat' => $now,
            'resetat' => null,
            'resetby' => null,
            'resetreason' => null,
            'qualityjson' => $this->encode($quality),
            'servermetadata' => $this->encode($servermetadata),
            'timemodified' => $now,
            'usermodified' => $usermodified,
        ];

        if ($existing) {
            $record->id = (int) $existing->id;
            $record->timecreated = (int) $existing->timecreated;
            $DB->update_record(self::TABLE, $record);
            return $DB->get_record(self::TABLE, ['id' => $record->id], '*', MUST_EXIST);
        }

        $record->timecreated = $now;
        $record->id = (int) $DB->insert_record(self::TABLE, $record);
        return $DB->get_record(self::TABLE, ['id' => $record->id], '*', MUST_EXIST);
    }

    /**
     * Marks the reference reset so the next proctored exam re-enrolls.
     *
     * @param int $userid User id.
     * @param int $actoruserid Administrator user id.
     * @param string $reason Reset reason.
     * @return \stdClass|null Updated row, or null when no row existed.
     */
    public function mark_reset(int $userid, int $actoruserid, string $reason): ?\stdClass {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['userid' => $userid]);
        if (!$record) {
            return null;
        }

        $record->status = 'reset';
        $record->resetat = time();
        $record->resetby = $actoruserid;
        $record->resetreason = clean_param($reason, PARAM_TEXT);
        $record->timemodified = time();
        $record->usermodified = $actoruserid;
        $DB->update_record(self::TABLE, $record);

        return $DB->get_record(self::TABLE, ['id' => (int) $record->id], '*', MUST_EXIST);
    }

    /**
     * Marks a user reference deleted after account removal or approved erasure.
     *
     * @param int $userid User id.
     * @param int|null $actoruserid Actor user id.
     * @param string $reason Erasure reason.
     * @return void
     */
    public function mark_deleted(int $userid, ?int $actoruserid, string $reason): void {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['userid' => $userid]);
        if (!$record) {
            return;
        }

        $record->status = 'deleted';
        $record->referencekey = null;
        $record->server_referenceid = null;
        $record->resetat = time();
        $record->resetby = $actoruserid;
        $record->resetreason = clean_param($reason, PARAM_TEXT);
        $record->timemodified = time();
        $record->usermodified = $actoruserid;
        $DB->update_record(self::TABLE, $record);
    }

    /** @return string */
    private function encode(array $data): string {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \coding_exception('Unable to encode face enrollment metadata.');
        }
        return $encoded;
    }
}
