<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves IOMAD company scope for users, courses, attempts, and reports.
<<<<<<< HEAD
 */
final class tenant_resolver {
=======
 *
 * The resolver supports both the legacy IOMAD table names used by Moodle/IOMAD
 * 4.x and the local_iomad-prefixed names introduced by newer IOMAD releases.
 * It deliberately returns company id 0 on a normal Moodle installation.
 *
 * @package     local_proctorcore
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tenant_resolver {
    /** Legacy IOMAD user/company table. */
    private const LEGACY_USER_TABLE = 'company_users';

    /** Newer IOMAD user/company table. */
    private const CURRENT_USER_TABLE = 'local_iomad_company_users';

    /** Legacy IOMAD course/company table. */
    private const LEGACY_COURSE_TABLE = 'company_course';

    /** Newer IOMAD course/company table. */
    private const CURRENT_COURSE_TABLE = 'local_iomad_company_courses';

    /**
     * Returns true when an IOMAD company/user table is installed.
     *
     * @return bool
     */
    public function is_iomad_available(): bool {
        return $this->table_exists(self::CURRENT_USER_TABLE)
            || $this->table_exists(self::LEGACY_USER_TABLE)
            || class_exists('\\local_iomad\\iomad');
    }

    /**
     * Gets all company ids assigned to a user.
     *
     * @param int $userid Moodle user id.
     * @return int[] Sorted unique company ids.
     */
    public function get_user_company_ids(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            return [];
        }

        $table = $this->first_existing_table([
            self::CURRENT_USER_TABLE,
            self::LEGACY_USER_TABLE,
        ]);

        if ($table === null) {
            return [];
        }

        $columns = $DB->get_columns($table);
        $companyfield = isset($columns['companyid']) ? 'companyid' : (isset($columns['company']) ? 'company' : '');
        $userfield = isset($columns['userid']) ? 'userid' : (isset($columns['user']) ? 'user' : '');

        if ($companyfield === '' || $userfield === '') {
            return [];
        }

        $ids = $DB->get_fieldset_select(
            $table,
            $companyfield,
            "{$userfield} = :userid",
            ['userid' => $userid]
        );

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Gets company ids to which a course is allocated.
     *
     * @param int $courseid Moodle course id.
     * @return int[] Sorted unique company ids.
     */
    public function get_course_company_ids(int $courseid): array {
        global $DB;

        if ($courseid <= 0) {
            return [];
        }

        $table = $this->first_existing_table([
            self::CURRENT_COURSE_TABLE,
            self::LEGACY_COURSE_TABLE,
        ]);

        if ($table === null) {
            return [];
        }

        $columns = $DB->get_columns($table);
        $companyfield = isset($columns['companyid']) ? 'companyid' : (isset($columns['company']) ? 'company' : '');
        $coursefield = isset($columns['courseid']) ? 'courseid' : (isset($columns['course']) ? 'course' : '');

        if ($companyfield === '' || $coursefield === '') {
            return [];
        }

        $ids = $DB->get_fieldset_select(
            $table,
            $companyfield,
            "{$coursefield} = :courseid",
            ['courseid' => $courseid]
        );

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Resolves the most appropriate company for a user and optional course.
     *
     * Resolution order:
     * 1. IOMAD's currently selected company, when available and valid.
     * 2. The only company shared by the user and course.
     * 3. The user's only company.
     * 4. Company 0 for a plain Moodle site or a site administrator.
     *
     * @param int $userid Moodle user id.
     * @param int $courseid Optional Moodle course id.
     * @return int Company id, or 0 for global/plain Moodle scope.
     * @throws \moodle_exception When tenant membership is ambiguous.
     */
    public function resolve_company_id(int $userid, int $courseid = 0): int {
        $usercompanies = $this->get_user_company_ids($userid);

        if (!$this->is_iomad_available()) {
            return 0;
        }

        $selectedcompany = $this->get_selected_iomad_company_id();
        if ($selectedcompany > 0 && in_array($selectedcompany, $usercompanies, true)) {
            if ($courseid <= 0 || $this->is_course_available_to_company($courseid, $selectedcompany)) {
                return $selectedcompany;
            }
        }

        if ($courseid > 0) {
            $coursecompanies = $this->get_course_company_ids($courseid);
            if ($coursecompanies) {
                $matches = array_values(array_intersect($usercompanies, $coursecompanies));
                if (count($matches) === 1) {
                    return (int) $matches[0];
                }
                if (count($matches) > 1) {
                    throw new \moodle_exception('error:ambiguouscompany', 'local_proctorcore');
                }
            }
        }

        if (count($usercompanies) === 1) {
            return (int) reset($usercompanies);
        }

        if (!$usercompanies && is_siteadmin($userid)) {
            return 0;
        }

        if (!$usercompanies) {
            throw new \moodle_exception('error:nocompany', 'local_proctorcore');
        }

        throw new \moodle_exception('error:ambiguouscompany', 'local_proctorcore');
    }

    /**
     * Confirms a user belongs to a company.
     *
     * @param int $userid Moodle user id.
     * @param int $companyid IOMAD company id.
     * @return bool
     */
    public function user_belongs_to_company(int $userid, int $companyid): bool {
        if ($companyid === 0 && !$this->is_iomad_available()) {
            return true;
        }
        return in_array($companyid, $this->get_user_company_ids($userid), true);
    }

    /**
     * Throws when a user is outside the requested tenant.
     *
     * @param int $userid Moodle user id.
     * @param int $companyid IOMAD company id.
     * @return void
     */
    public function require_user_in_company(int $userid, int $companyid): void {
        if (!$this->user_belongs_to_company($userid, $companyid) && !is_siteadmin($userid)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/proctorcore:manage',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Checks whether a course is allocated to a company.
     *
     * An empty IOMAD course allocation table is treated as no additional
     * restriction because some sites use shared/global Moodle courses.
     *
     * @param int $courseid Course id.
     * @param int $companyid Company id.
     * @return bool
     */
    public function is_course_available_to_company(int $courseid, int $companyid): bool {
        $coursecompanies = $this->get_course_company_ids($courseid);
        return !$coursecompanies || in_array($companyid, $coursecompanies, true);
    }

    /**
     * Gets IOMAD's selected company for the current request when possible.
     *
     * @return int
     */
    private function get_selected_iomad_company_id(): int {
        if (!class_exists('\\local_iomad\\iomad')
                || !method_exists('\\local_iomad\\iomad', 'get_my_companyid')) {
            return 0;
        }

        try {
            return (int) \local_iomad\iomad::get_my_companyid(\context_system::instance());
        } catch (\Throwable $exception) {
            debugging('ProctorCore could not read the selected IOMAD company: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return 0;
        }
    }

    /**
     * Returns the first installed table from a list.
     *
     * @param string[] $tables Table names without Moodle prefix.
     * @return string|null
     */
    private function first_existing_table(array $tables): ?string {
        foreach ($tables as $table) {
            if ($this->table_exists($table)) {
                return $table;
            }
        }
        return null;
    }

    /**
     * Checks a Moodle table through XMLDB.
     *
     * @param string $tablename Table name without Moodle prefix.
     * @return bool
     */
    private function table_exists(string $tablename): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table($tablename));
    }
>>>>>>> origin/danial
}
