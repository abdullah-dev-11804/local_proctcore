<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles appeal submission, review state, and evidence retention holds.
 */
final class appeal_service {
    /** Places both Moodle and proctoring-server evidence under appeal hold. */
    public function hold_session_evidence(int $sessionid, string $reason, int $actorid): void {
        global $DB;
        $sessions = new session_repository();
        $session = $sessions->get_by_id($sessionid);
        if (empty($session->server_sessionid)) {
            throw new \moodle_exception('error:missingserversession', 'local_proctorcore');
        }
        (new server_client((int) $session->companyid))->hold_evidence((string) $session->server_sessionid, $reason);
        $assets = new asset_repository();
        foreach ($assets->get_for_session($sessionid) as $asset) {
            $assets->mark_held((int) $asset->id);
        }
        $DB->set_field('local_proctorcore_sessions', 'appealstatus', 'held', ['id' => $sessionid]);
        (new audit_logger())->log(
            'retention.evidence_held', (int) $session->companyid, $sessionid, (int) $session->userid,
            ['reason' => clean_param($reason, PARAM_TEXT)], $actorid, 'session', $sessionid
        );
    }

    /** Releases an appeal hold after course completion and assigns a final expiry. */
    public function release_session_evidence(
        int $sessionid,
        int $coursecompletedat,
        string $reason,
        int $actorid
    ): void {
        global $DB;
        $sessions = new session_repository();
        $session = $sessions->get_by_id($sessionid);
        if (empty($session->server_sessionid)) {
            throw new \moodle_exception('error:missingserversession', 'local_proctorcore');
        }
        (new server_client((int) $session->companyid))->release_evidence(
            (string) $session->server_sessionid,
            $reason
        );
        $expiry = (new retention_policy())->extend_for_course_completion($coursecompletedat);
        $assets = new asset_repository();
        foreach ($assets->get_for_session($sessionid) as $asset) {
            $assets->release_hold((int) $asset->id, max($expiry, (int) ($asset->expiresat ?? 0)));
        }
        $DB->set_field('local_proctorcore_sessions', 'appealstatus', 'released', ['id' => $sessionid]);
        (new audit_logger())->log(
            'retention.evidence_released', (int) $session->companyid, $sessionid, (int) $session->userid,
            ['reason' => clean_param($reason, PARAM_TEXT), 'expiresAt' => $expiry],
            $actorid, 'session', $sessionid
        );
    }
}
