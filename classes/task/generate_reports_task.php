<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\task;

use local_proctorcore\local\report_pdf_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Generates missing or stale reports for ended sessions.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generate_reports_task extends \core\task\scheduled_task {
    /** @return string */
    public function get_name(): string {
        return get_string('task:generate_reports', 'local_proctorcore');
    }

    /** @return void */
    public function execute(): void {
        global $DB;

        $statuses = ['completed', 'failed', 'abandoned', 'expired'];
        [$insql, $params] = $DB->get_in_or_equal($statuses, SQL_PARAMS_NAMED, 'reportstatus');
        $params['reporttype'] = \local_proctorcore\local\asset_repository::TYPE_REPORT;
        $params['moodlestorage'] = 'moodle_file';
        $params['activeasset'] = 'active';
        $params['sourcereporttype'] = \local_proctorcore\local\asset_repository::TYPE_REPORT;
        $params['sourceactive'] = 'active';
        $params['now'] = time();
        $sql = "SELECT s.id
                  FROM {local_proctorcore_sessions} s
                 WHERE (s.endedat IS NOT NULL OR s.status {$insql})
                   AND (s.reportexpiresat IS NULL OR s.reportexpiresat > :now)
                   AND NOT EXISTS (
                       SELECT 1
                         FROM {local_proctorcore_assets} a
                        WHERE a.sessionid = s.id
                          AND a.assettype = :reporttype
                          AND a.storage = :moodlestorage
                          AND a.status = :activeasset
                          AND a.deletedat IS NULL
                          AND a.timemodified >= s.timemodified
                          AND a.timemodified >= COALESCE((
                              SELECT MAX(v.timemodified)
                                FROM {local_proctorcore_violations} v
                               WHERE v.sessionid = s.id
                          ), 0)
                          AND a.timemodified >= COALESCE((
                              SELECT MAX(sourceasset.timemodified)
                                FROM {local_proctorcore_assets} sourceasset
                               WHERE sourceasset.sessionid = s.id
                                 AND sourceasset.assettype <> :sourcereporttype
                                 AND sourceasset.status = :sourceactive
                                 AND sourceasset.deletedat IS NULL
                          ), 0)
                   )
              ORDER BY COALESCE(s.endedat, s.timemodified) ASC, s.id ASC";
        $sessionids = $DB->get_fieldset_sql($sql, $params, 0, 50);
        if (!$sessionids) {
            mtrace('ProctorCore report generation: no ended sessions found.');
            return;
        }

        $service = new report_pdf_service();
        $generated = 0;
        $failed = 0;
        foreach ($sessionids as $sessionid) {
            try {
                $service->get_or_generate((int) $sessionid, null);
                $generated++;
            } catch (\Throwable $exception) {
                $failed++;
                mtrace('ProctorCore could not generate report for session ' . (int) $sessionid
                    . ': ' . clean_param($exception->getMessage(), PARAM_TEXT));
            }
        }
        mtrace("ProctorCore report generation finished: {$generated} checked/generated, {$failed} failed.");
    }
}
