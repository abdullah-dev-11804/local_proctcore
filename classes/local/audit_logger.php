<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Writes append-only administrator, coordinator, proctor, and integration audit events.
<<<<<<< HEAD
 */
final class audit_logger {
    public const TABLE = 'local_proctorcore_audit';
=======
 *
 * The class intentionally exposes no update or delete method. Audit rows are append-only.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class audit_logger {
    /** Audit table. */
    public const TABLE = 'local_proctorcore_audit';

    /**
     * Writes one append-only audit entry.
     *
     * @param string $action Stable machine-readable action name.
     * @param int $companyid IOMAD company id, or 0 for global Moodle scope.
     * @param int|null $sessionid Related ProctorCore session id.
     * @param int|null $relateduserid Student or other related Moodle user id.
     * @param array $details Additional JSON-safe details.
     * @param int|null $actoruserid Moodle user performing the action; null for Server B.
     * @param string|null $targettype Optional target type.
     * @param int|null $targetid Optional target record id.
     * @return int Inserted audit row id.
     */
    public function log(
        string $action,
        int $companyid,
        ?int $sessionid = null,
        ?int $relateduserid = null,
        array $details = [],
        ?int $actoruserid = null,
        ?string $targettype = null,
        ?int $targetid = null
    ): int {
        global $DB;

        if (!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $action)) {
            throw new \coding_exception('Invalid ProctorCore audit action: ' . $action);
        }

        $encoded = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \coding_exception('Unable to encode ProctorCore audit details.');
        }

        $ipaddress = null;
        if (function_exists('getremoteaddr')) {
            $ipaddress = getremoteaddr(null);
        }

        $record = (object) [
            'companyid' => max(0, $companyid),
            'sessionid' => $sessionid,
            'userid' => $actoruserid,
            'relateduserid' => $relateduserid,
            'action' => $action,
            'targettype' => $targettype,
            'targetid' => $targetid,
            'ipaddress' => $ipaddress ?: null,
            'useragent' => isset($_SERVER['HTTP_USER_AGENT'])
                ? clean_param((string) $_SERVER['HTTP_USER_AGENT'], PARAM_TEXT)
                : null,
            'details' => $encoded,
            'timecreated' => time(),
        ];

        return (int) $DB->insert_record(self::TABLE, $record);
    }
>>>>>>> origin/danial
}
