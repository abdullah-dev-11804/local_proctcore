<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves IOMAD company scope for users, courses, attempts, and reports.
 */
final class tenant_resolver {

    /**
     * Retrieves the primary IOMAD company ID for a given user.
     * Fallbacks to 0 (site level) if IOMAD is not installed or the user has no company.
     *
     * @param int $userid The Moodle user ID.
     * @return int The IOMAD company ID.
     */
    public static function get_user_companyid(int $userid): int {
        global $DB;

        // Defensive check: Verify IOMAD is actually installed by checking its core table.
        if (!$DB->get_manager()->table_exists('local_iomad_company_users')) {
            return 0; 
        }

        // Query the IOMAD table to find the user's company assignment.
        // If a user belongs to multiple companies, we grab the first/oldest assignment safely.
        $sql = "SELECT companyid 
                  FROM {local_iomad_company_users} 
                 WHERE userid = ? 
              ORDER BY timecreated ASC";
              
        $records = $DB->get_records_sql($sql, [$userid], 0, 1);

        if ($records) {
            $record = reset($records);
            return (int)$record->companyid;
        }

        return 0; // User is not assigned to any company
    }
}