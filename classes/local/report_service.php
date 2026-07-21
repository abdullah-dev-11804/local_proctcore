<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
<<<<<<< HEAD
 * Builds company-scoped report data from sessions, violations, assets, and quiz attempts.
 */
final class report_service {
=======
 * Builds tenant-aware proctoring report data.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_service {
    /** @var tenant_resolver */
    private $tenants;

    /** @var asset_repository */
    private $assets;

    /**
     * Constructor.
     *
     * @param tenant_resolver|null $tenants Optional tenant resolver.
     * @param asset_repository|null $assets Optional asset repository.
     */
    public function __construct(?tenant_resolver $tenants = null, ?asset_repository $assets = null) {
        $this->tenants = $tenants ?? new tenant_resolver();
        $this->assets = $assets ?? new asset_repository();
    }

    /**
     * Returns one authorised session report.
     *
     * @param int $sessionid Local ProctorCore session id.
     * @param int $viewerid Moodle user requesting the report.
     * @return array
     */
    public function get_session_report(int $sessionid, int $viewerid): array {
        $session = $this->get_session_record($sessionid);
        $this->require_can_view_session($session, $viewerid);
        return $this->build_report($session);
    }

    /**
     * Returns report data for internal PDF generation and scheduled tasks.
     *
     * @param int $sessionid Local session id.
     * @return array
     */
    public function get_session_report_for_generation(int $sessionid): array {
        return $this->build_report($this->get_session_record($sessionid));
    }

    /**
     * Lists reports visible to a user.
     *
     * @param int $viewerid Moodle user id.
     * @param int $page Zero-based page.
     * @param int $perpage Rows per page.
     * @param array $filters Optional companyid, courseid, quizid, userid, result and status filters.
     * @return array{records: array, total: int, page: int, perpage: int}
     */
    public function list_reports(
        int $viewerid,
        int $page = 0,
        int $perpage = 25,
        array $filters = []
    ): array {
        global $DB;

        $page = max(0, $page);
        $perpage = min(100, max(1, $perpage));
        $where = [
            '(s.endedat IS NOT NULL OR s.status IN (:terminalcompleted, :terminalfailed, :terminalabandoned, :terminalexpired))',
        ];
        $params = [
            'terminalcompleted' => 'completed',
            'terminalfailed' => 'failed',
            'terminalabandoned' => 'abandoned',
            'terminalexpired' => 'expired',
        ];
        $scope = $this->get_access_scope($viewerid, $filters);

        if ($scope['mode'] === 'own') {
            $where[] = 's.userid = :scopeuserid';
            $params['scopeuserid'] = $viewerid;
        } else if ($scope['mode'] === 'companies') {
            if (!$scope['companyids']) {
                return ['records' => [], 'total' => 0, 'page' => $page, 'perpage' => $perpage];
            }
            [$insql, $inparams] = $DB->get_in_or_equal(
                $scope['companyids'],
                SQL_PARAMS_NAMED,
                'scopecompany'
            );
            $where[] = "s.companyid {$insql}";
            $params += $inparams;
        }

        foreach (['companyid', 'courseid', 'quizid', 'userid'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = 's.' . $field . ' = :filter' . $field;
                $params['filter' . $field] = (int) $filters[$field];
            }
        }
        foreach (['result', 'status'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = 's.' . $field . ' = :filter' . $field;
                $params['filter' . $field] = clean_param((string) $filters[$field], PARAM_ALPHANUMEXT);
            }
        }

        $wheresql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $fromsql = "FROM {local_proctorcore_sessions} s
                    JOIN {user} u ON u.id = s.userid
                    JOIN {course} c ON c.id = s.courseid
                    JOIN {quiz} q ON q.id = s.quizid
               LEFT JOIN {quiz_attempts} qa ON qa.id = s.attemptid";

        $total = (int) $DB->count_records_sql("SELECT COUNT(1) {$fromsql} {$wheresql}", $params);
        $sql = "SELECT s.id, s.companyid, s.courseid, s.cmid, s.quizid, s.attemptid, s.userid,
                       s.server_sessionid, s.status, s.result, s.identitystatus,
                       s.techcheckstatus, s.violationcount, s.snapshotcount,
                       s.startedat, s.endedat, s.reportexpiresat, s.timemodified,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.email,
                       c.fullname AS coursename, c.shortname AS courseshortname,
                       q.name AS quizname, qa.attempt AS attemptnumber,
                       qa.state AS attemptstate, qa.timestart AS quiztimestart,
                       qa.timefinish AS quiztimefinish
                  {$fromsql}
                  {$wheresql}
              ORDER BY COALESCE(s.endedat, s.startedat, s.timecreated) DESC, s.id DESC";

        $records = array_values($DB->get_records_sql($sql, $params, $page * $perpage, $perpage));
        foreach ($records as $record) {
            $record->studentname = fullname($record);
            $record->companyname = $this->get_company_name((int) $record->companyid);
        }

        return [
            'records' => $records,
            'total' => $total,
            'page' => $page,
            'perpage' => $perpage,
        ];
    }

    /**
     * Checks report visibility.
     *
     * @param \stdClass $session Session row or joined report row.
     * @param int $viewerid Viewer id.
     * @return bool
     */
    public function can_view_session(\stdClass $session, int $viewerid): bool {
        global $CFG;
        if ($viewerid <= 0 || $viewerid === (int) $CFG->siteguest) {
            return false;
        }
        if ((int) $session->userid === $viewerid || is_siteadmin($viewerid)) {
            return true;
        }

        // A teacher who can view Moodle Quiz reports for this exact activity may
        // also view the linked ProctorCore report. This keeps teacher access
        // limited to quizzes they are authorised to manage or report on.
        if ($this->can_view_quiz_reports($session, $viewerid)) {
            return true;
        }

        $systemcontext = \context_system::instance();
        if (has_capability('local/proctorcore:viewallreports', $systemcontext, $viewerid)) {
            return true;
        }
        if (!has_capability('local/proctorcore:viewcompanyreports', $systemcontext, $viewerid)) {
            return false;
        }

        $companyid = (int) $session->companyid;
        if (!$this->tenants->is_iomad_available()) {
            return $companyid === 0;
        }
        if ($companyid <= 0) {
            return false;
        }
        return $this->tenants->user_belongs_to_company($viewerid, $companyid);
    }

    /**
     * Requires report visibility.
     *
     * @param \stdClass $session Session row.
     * @param int $viewerid Viewer id.
     * @return void
     */
    public function require_can_view_session(\stdClass $session, int $viewerid): void {
        if (!$this->can_view_session($session, $viewerid)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/proctorcore:viewcompanyreports',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Checks PDF download permission.
     *
     * Students may download their own report. Other viewers also need exportreports.
     *
     * @param \stdClass $session Session row.
     * @param int $viewerid Viewer id.
     * @return bool
     */
    public function can_download_session(\stdClass $session, int $viewerid): bool {
        if (!$this->can_view_session($session, $viewerid)) {
            return false;
        }
        if (!empty($session->reportexpiresat) && (int) $session->reportexpiresat <= time()) {
            return false;
        }
        if ((int) $session->userid === $viewerid || is_siteadmin($viewerid)) {
            return true;
        }
        if ($this->can_view_quiz_reports($session, $viewerid)) {
            return true;
        }
        return has_capability(
            'local/proctorcore:exportreports',
            \context_system::instance(),
            $viewerid
        );
    }

    /**
     * Gets one session with student, course, Quiz, and attempt data.
     *
     * @param int $sessionid Session id.
     * @return \stdClass
     */
    public function get_session_record(int $sessionid): \stdClass {
        global $DB;

        $sql = "SELECT s.*,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.email, u.idnumber AS studentidnumber,
                       c.fullname AS coursename, c.shortname AS courseshortname,
                       q.name AS quizname, q.grade AS quizgrade, q.sumgrades AS quizsumgrades,
                       qa.attempt AS attemptnumber, qa.state AS attemptstate,
                       qa.timestart AS quiztimestart, qa.timefinish AS quiztimefinish,
                       qa.sumgrades AS attemptsumgrades
                  FROM {local_proctorcore_sessions} s
                  JOIN {user} u ON u.id = s.userid
                  JOIN {course} c ON c.id = s.courseid
                  JOIN {quiz} q ON q.id = s.quizid
             LEFT JOIN {quiz_attempts} qa ON qa.id = s.attemptid
                 WHERE s.id = :sessionid";

        $record = $DB->get_record_sql($sql, ['sessionid' => $sessionid], MUST_EXIST);
        $record->studentname = fullname($record);
        $record->companyname = $this->get_company_name((int) $record->companyid);
        return $record;
    }

    /**
     * Returns violations in timestamp order.
     *
     * @param int $sessionid Session id.
     * @return \stdClass[]
     */
    public function get_violations(int $sessionid): array {
        global $DB;
        return array_values($DB->get_records(
            'local_proctorcore_violations',
            ['sessionid' => $sessionid],
            'occurredat ASC, id ASC'
        ));
    }

    /**
     * Returns active assets grouped for report rendering.
     *
     * @param int $sessionid Session id.
     * @return array
     */
    public function get_report_assets(int $sessionid): array {
        $grouped = [
            'identity' => [],
            'submission' => [],
            'violations' => [],
            'snapshots' => [],
            'videos' => [],
            'reports' => [],
            'other' => [],
        ];

        foreach ($this->assets->get_for_session($sessionid) as $asset) {
            if ((string) $asset->status !== 'active' || !empty($asset->deletedat)) {
                continue;
            }
            $metadata = json_decode((string) ($asset->metadata ?? ''), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $asset->metadataarray = $metadata;
            $asset->reason = strtolower((string) ($metadata['reason'] ?? $metadata['snapshotReason'] ?? ''));
            $asset->filename = $this->asset_filename($asset, $metadata);

            switch ((string) $asset->assettype) {
                case asset_repository::TYPE_IDENTITY_PHOTO:
                    $grouped['identity'][] = $asset;
                    break;
                case asset_repository::TYPE_SNAPSHOT:
                    if ($asset->reason === 'submission') {
                        $grouped['submission'][] = $asset;
                    } else if (!empty($asset->violationid) || $asset->reason === 'violation') {
                        $grouped['violations'][] = $asset;
                    } else {
                        $grouped['snapshots'][] = $asset;
                    }
                    break;
                case asset_repository::TYPE_VIDEO_CLIP:
                case asset_repository::TYPE_ROOM_SCAN:
                    $grouped['videos'][] = $asset;
                    break;
                case asset_repository::TYPE_REPORT:
                    $grouped['reports'][] = $asset;
                    break;
                default:
                    $grouped['other'][] = $asset;
                    break;
            }
        }

        return $grouped;
    }

    /**
     * Returns the latest technical check.
     *
     * @param int $sessionid Session id.
     * @return \stdClass|null
     */
    public function get_latest_check(int $sessionid): ?\stdClass {
        global $DB;
        $sql = "SELECT *
                  FROM {local_proctorcore_checks}
                 WHERE sessionid = :sessionid
              ORDER BY timecreated DESC, id DESC";
        $record = $DB->get_record_sql($sql, ['sessionid' => $sessionid], IGNORE_MULTIPLE);
        return $record ?: null;
    }

    /**
     * Returns configurable participant field values.
     *
     * @param int $sessionid Session id.
     * @return array
     */
    public function get_participant_fields(int $sessionid): array {
        global $DB;
        $sql = "SELECT f.id, f.shortname, f.name, f.datatype, v.value
                  FROM {local_proctorcore_fieldvals} v
                  JOIN {local_proctorcore_fields} f ON f.id = v.fieldid
                 WHERE v.sessionid = :sessionid
              ORDER BY f.sortorder ASC, f.id ASC";
        return array_values($DB->get_records_sql($sql, ['sessionid' => $sessionid]));
    }

    /**
     * Computes the newest source modification time for PDF staleness checks.
     *
     * @param int $sessionid Session id.
     * @return int
     */
    public function get_source_modified(int $sessionid): int {
        global $DB;
        $sessionmodified = (int) $DB->get_field(
            'local_proctorcore_sessions',
            'timemodified',
            ['id' => $sessionid],
            MUST_EXIST
        );
        $violationmodified = (int) $DB->get_field_sql(
            "SELECT COALESCE(MAX(timemodified), 0)
               FROM {local_proctorcore_violations}
              WHERE sessionid = :sessionid",
            ['sessionid' => $sessionid]
        );
        $assetmodified = (int) $DB->get_field_sql(
            "SELECT COALESCE(MAX(timemodified), 0)
               FROM {local_proctorcore_assets}
              WHERE sessionid = :sessionid
                AND status = :active
                AND assettype <> :reporttype",
            [
                'sessionid' => $sessionid,
                'active' => 'active',
                'reporttype' => asset_repository::TYPE_REPORT,
            ]
        );
        return max($sessionmodified, $violationmodified, $assetmodified);
    }

    /**
     * Builds the complete report model.
     *
     * @param \stdClass $session Joined session row.
     * @return array
     */
    private function build_report(\stdClass $session): array {
        $violations = $this->get_violations((int) $session->id);
        $assets = $this->get_report_assets((int) $session->id);
        $check = $this->get_latest_check((int) $session->id);
        $fields = $this->get_participant_fields((int) $session->id);

        $percent = null;
        $grade = null;
        if ($session->attemptsumgrades !== null && (float) $session->quizsumgrades > 0) {
            $percent = round(((float) $session->attemptsumgrades / (float) $session->quizsumgrades) * 100, 2);
            $grade = round(((float) $session->attemptsumgrades / (float) $session->quizsumgrades)
                * (float) $session->quizgrade, 2);
        }

        $start = (int) ($session->startedat ?: $session->quiztimestart ?: $session->timecreated);
        $end = (int) ($session->endedat ?: $session->quiztimefinish ?: 0);
        $duration = $end > $start ? $end - $start : 0;

        return [
            'session' => $session,
            'violations' => $violations,
            'assets' => $assets,
            'check' => $check,
            'participantfields' => $fields,
            'starttime' => $start,
            'endtime' => $end,
            'duration' => $duration,
            'grade' => $grade,
            'percent' => $percent,
            'sourceModified' => $this->get_source_modified((int) $session->id),
        ];
    }

    /**
     * Gets the access scope for list queries.
     *
     * @param int $viewerid Viewer id.
     * @return array
     */
    private function get_access_scope(int $viewerid, array $filters = []): array {
        $systemcontext = \context_system::instance();
        if (is_siteadmin($viewerid)
                || has_capability('local/proctorcore:viewallreports', $systemcontext, $viewerid)) {
            return ['mode' => 'all', 'companyids' => []];
        }
        if (has_capability('local/proctorcore:viewcompanyreports', $systemcontext, $viewerid)) {
            $ids = $this->tenants->is_iomad_available()
                ? $this->tenants->get_user_company_ids($viewerid)
                : [0];
            return ['mode' => 'companies', 'companyids' => $ids];
        }

        // Quiz/course links include a narrow filter. Teachers who have Moodle's
        // native Quiz report permission in that context may list only those
        // filtered reports; they never receive an unrestricted global list.
        if ($this->can_view_filtered_quiz_reports($viewerid, $filters)) {
            return ['mode' => 'teacher', 'companyids' => []];
        }

        return ['mode' => 'own', 'companyids' => []];
    }

    /**
     * Checks whether a viewer may access the Quiz report linked to a session.
     *
     * @param \stdClass $session Session row containing cmid/quizid/courseid.
     * @param int $viewerid Viewer id.
     * @return bool
     */
    private function can_view_quiz_reports(\stdClass $session, int $viewerid): bool {
        global $DB;

        $cmid = (int) ($session->cmid ?? 0);
        if ($cmid <= 0 && !empty($session->quizid)) {
            $moduleid = (int) $DB->get_field('modules', 'id', ['name' => 'quiz'], IGNORE_MISSING);
            if ($moduleid > 0) {
                $conditions = ['module' => $moduleid, 'instance' => (int) $session->quizid];
                if (!empty($session->courseid)) {
                    $conditions['course'] = (int) $session->courseid;
                }
                $cmid = (int) $DB->get_field('course_modules', 'id', $conditions, IGNORE_MISSING);
            }
        }
        if ($cmid <= 0) {
            return false;
        }

        try {
            $context = \context_module::instance($cmid);
            return has_capability('mod/quiz:viewreports', $context, $viewerid)
                || has_capability('moodle/course:manageactivities', $context, $viewerid);
        } catch (\Throwable $exception) {
            return false;
        }
    }

    /**
     * Checks teacher access for a deliberately filtered report list.
     *
     * @param int $viewerid Viewer id.
     * @param array $filters List filters.
     * @return bool
     */
    private function can_view_filtered_quiz_reports(int $viewerid, array $filters): bool {
        global $DB;

        $quizid = (int) ($filters['quizid'] ?? 0);
        $courseid = (int) ($filters['courseid'] ?? 0);

        if ($quizid > 0) {
            $record = $DB->get_record('local_proctorcore_sessions', ['quizid' => $quizid],
                'cmid,quizid,courseid', IGNORE_MULTIPLE);
            if (!$record) {
                $moduleid = (int) $DB->get_field('modules', 'id', ['name' => 'quiz'], IGNORE_MISSING);
                $cm = $moduleid > 0
                    ? $DB->get_record('course_modules', ['module' => $moduleid, 'instance' => $quizid],
                        'id,instance,course', IGNORE_MULTIPLE)
                    : false;
                if ($cm) {
                    $record = (object) [
                        'cmid' => (int) $cm->id,
                        'quizid' => (int) $cm->instance,
                        'courseid' => (int) $cm->course,
                    ];
                } else {
                    $record = null;
                }
            }
            return $record ? $this->can_view_quiz_reports($record, $viewerid) : false;
        }

        if ($courseid > 0) {
            try {
                $context = \context_course::instance($courseid);
                return has_capability('moodle/course:manageactivities', $context, $viewerid)
                    || has_capability('mod/quiz:viewreports', $context, $viewerid);
            } catch (\Throwable $exception) {
                return false;
            }
        }

        return false;
    }

    /**
     * Returns a company display name without hard-depending on one IOMAD schema.
     *
     * @param int $companyid Company id.
     * @return string
     */
    private function get_company_name(int $companyid): string {
        global $DB;

        if ($companyid <= 0) {
            return get_string('report:globalcompany', 'local_proctorcore');
        }
        foreach (['company', 'local_iomad_company'] as $tablename) {
            $table = new \xmldb_table($tablename);
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }
            $columns = $DB->get_columns($tablename);
            foreach (['name', 'companyname', 'shortname'] as $field) {
                if (isset($columns[$field])) {
                    $name = $DB->get_field($tablename, $field, ['id' => $companyid], IGNORE_MISSING);
                    if ($name !== false && trim((string) $name) !== '') {
                        return format_string((string) $name);
                    }
                }
            }
        }
        return get_string('report:companynumber', 'local_proctorcore', $companyid);
    }

    /**
     * Derives a safe display filename.
     *
     * @param \stdClass $asset Asset row.
     * @param array $metadata Decoded metadata.
     * @return string
     */
    private function asset_filename(\stdClass $asset, array $metadata): string {
        foreach (['filename', 'fileName', 'relativePath', 'path'] as $key) {
            if (!empty($metadata[$key])) {
                return clean_param(basename((string) $metadata[$key]), PARAM_FILE);
            }
        }
        if (!empty($asset->url)) {
            $path = parse_url((string) $asset->url, PHP_URL_PATH);
            if ($path) {
                return clean_param(basename($path), PARAM_FILE);
            }
        }
        return clean_param((string) $asset->assettype . '-' . (int) $asset->id, PARAM_FILE);
    }
>>>>>>> origin/danial
}
