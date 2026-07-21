<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Repository for Section 1.3 behaviour violations.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class violation_repository {
    /** Table name. */
    public const TABLE = 'local_proctorcore_violations';

    /**
     * Creates a violation and updates session risk counters.
     *
     * @param int $sessionid Session id.
     * @param string $type Machine-readable type.
     * @param int $severity 1..5.
     * @param string $source browser, ml_service, identity_model, or proctor.
     * @param array $data Optional occurredat, durationms, description, metadata, servereventid.
     * @return \stdClass
     */
    public function create(
        int $sessionid,
        string $type,
        int $severity,
        string $source,
        array $data = []
    ): \stdClass {
        global $DB;

        $session = $DB->get_record('local_proctorcore_sessions', ['id' => $sessionid], '*', MUST_EXIST);
        $cleantype = clean_param($type, PARAM_ALPHANUMEXT);
        if ($cleantype === '') {
            throw new \coding_exception('Violation type cannot be empty.');
        }
        $severity = min(5, max(1, $severity));
        $metadata = $data['metadata'] ?? [];
        $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \coding_exception('Unable to encode violation metadata.');
        }

        $now = time();
        $record = (object) [
            'sessionid' => $sessionid,
            'companyid' => (int) $session->companyid,
            'userid' => (int) $session->userid,
            'servereventid' => isset($data['servereventid'])
                ? clean_param((string) $data['servereventid'], PARAM_TEXT)
                : null,
            'type' => \core_text::substr($cleantype, 0, 64),
            'severity' => $severity,
            'source' => \core_text::substr(clean_param($source, PARAM_ALPHANUMEXT), 0, 32),
            'status' => 'open',
            'occurredat' => isset($data['occurredat']) ? max(1, (int) $data['occurredat']) : $now,
            'durationms' => isset($data['durationms']) ? max(0, (int) $data['durationms']) : null,
            'description' => isset($data['description'])
                ? clean_param((string) $data['description'], PARAM_TEXT)
                : null,
            'metadata' => $encoded,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = (int) $DB->insert_record(self::TABLE, $record);

        // Use normal Moodle record updates rather than database-specific SQL
        // functions so this remains portable across supported database engines.
        $summary = $DB->get_record(
            'local_proctorcore_sessions',
            ['id' => $sessionid],
            'id,violationcount,risk_score',
            MUST_EXIST
        );
        $summary->violationcount = max(0, (int) $summary->violationcount) + 1;
        $summary->risk_score = min(100, max(0, (int) $summary->risk_score) + ($severity * 10));
        $summary->timemodified = $now;
        $DB->update_record('local_proctorcore_sessions', $summary);

        (new audit_logger())->log(
            'violation.created',
            (int) $session->companyid,
            $sessionid,
            (int) $session->userid,
            [
                'type' => $record->type,
                'severity' => $severity,
                'source' => $record->source,
                'occurredAt' => $record->occurredat,
                'durationMs' => $record->durationms,
            ],
            null,
            'violation',
            (int) $record->id
        );

        return $record;
    }

    /**
     * Finds recent same-type violation for cooldown suppression.
     *
     * @param int $sessionid Session id.
     * @param string $type Type.
     * @param int $since Cutoff timestamp.
     * @return \stdClass|null
     */
    public function get_recent(int $sessionid, string $type, int $since): ?\stdClass {
        global $DB;
        $record = $DB->get_record_sql(
            "SELECT *
               FROM {local_proctorcore_violations}
              WHERE sessionid = :sessionid
                AND type = :type
                AND occurredat >= :since
           ORDER BY occurredat DESC",
            ['sessionid' => $sessionid, 'type' => $type, 'since' => $since],
            IGNORE_MULTIPLE
        );
        return $record ?: null;
    }
}
