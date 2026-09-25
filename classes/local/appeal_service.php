<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles appeal submission, review state, and evidence retention holds.
 */
final class appeal_service {
    private const REASONS = ['technical_failure', 'identification_error', 'other'];

    /** Submits an appeal and immediately protects all locally indexed evidence. */
    public function submit(int $sessionid, int $userid, string $reason, string $details): \stdClass {
        global $DB;
        if (!in_array($reason, self::REASONS, true)) {
            throw new \moodle_exception('appeal:invalidreason', 'local_proctorcore');
        }
        $session = (new session_repository())->get_by_id($sessionid);
        if ((int) $session->userid !== $userid) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/proctorcore:viewownreport', 'nopermissions', '');
        }
        if (empty($session->endedat) || empty($session->appealuntil) || (int) $session->appealuntil < time()) {
            throw new \moodle_exception('appeal:windowclosed', 'local_proctorcore');
        }
        if ($DB->record_exists_select('local_proctorcore_appeals',
                'sessionid = :sessionid AND status <> :withdrawn', ['sessionid' => $sessionid, 'withdrawn' => 'withdrawn'])) {
            throw new \moodle_exception('appeal:alreadyexists', 'local_proctorcore');
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $appealid = (int) $DB->insert_record('local_proctorcore_appeals', (object) [
            'sessionid' => $sessionid,
            'companyid' => (int) $session->companyid,
            'userid' => $userid,
            'reason' => $reason,
            'details' => clean_param($details, PARAM_TEXT),
            'status' => 'hold_pending',
            'decision' => null,
            'reviewerid' => null,
            'submittedat' => $now,
            'decidedat' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        foreach ((new asset_repository())->get_for_session($sessionid) as $asset) {
            (new asset_repository())->mark_held((int) $asset->id);
        }
        $DB->update_record('local_proctorcore_sessions', (object) [
            'id' => $sessionid, 'appealstatus' => 'hold_pending', 'timemodified' => $now,
        ]);
        (new audit_logger())->log('appeal.submitted', (int) $session->companyid, $sessionid, $userid,
            ['appealId' => $appealid, 'reason' => $reason], $userid, 'appeal', $appealid);
        $transaction->allow_commit();

        $this->sync_hold($appealid);
        $this->notify_reviewers($appealid);
        return $DB->get_record('local_proctorcore_appeals', ['id' => $appealid], '*', MUST_EXIST);
    }

    /** Retries the remote hold without rolling back the already-safe local hold. */
    public function sync_hold(int $appealid): bool {
        global $DB;
        $appeal = $DB->get_record('local_proctorcore_appeals', ['id' => $appealid], '*', MUST_EXIST);
        $session = (new session_repository())->get_by_id((int) $appeal->sessionid);
        if (empty($session->server_sessionid)) {
            return false;
        }
        try {
            (new server_client((int) $session->companyid))->hold_evidence(
                (string) $session->server_sessionid, 'appeal:' . (int) $appeal->id
            );
            $DB->set_field('local_proctorcore_appeals', 'status', 'submitted', ['id' => $appealid]);
            $DB->set_field('local_proctorcore_appeals', 'timemodified', time(), ['id' => $appealid]);
            $DB->set_field('local_proctorcore_sessions', 'appealstatus', 'submitted', ['id' => (int) $session->id]);
            return true;
        } catch (\Throwable $exception) {
            debugging('ProctorCore appeal hold will be retried: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /** Records an authorised review decision while retaining evidence until course completion. */
    public function decide(int $appealid, int $reviewerid, string $status, string $decision): \stdClass {
        global $DB;
        if (!in_array($status, ['approved', 'rejected'], true) || trim($decision) === '') {
            throw new \moodle_exception('appeal:invaliddecision', 'local_proctorcore');
        }
        $appeal = $DB->get_record('local_proctorcore_appeals', ['id' => $appealid], '*', MUST_EXIST);
        $session = (new session_repository())->get_by_id((int) $appeal->sessionid);
        if (!(new report_service())->can_review_appeal($session, $reviewerid)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/proctorcore:reviewappeals',
                'nopermissions',
                ''
            );
        }
        $now = time();
        $appeal->status = $status;
        $appeal->decision = clean_param($decision, PARAM_TEXT);
        $appeal->reviewerid = $reviewerid;
        $appeal->decidedat = $now;
        $appeal->timemodified = $now;
        $DB->update_record('local_proctorcore_appeals', $appeal);
        $DB->update_record('local_proctorcore_sessions', (object) [
            'id' => (int) $session->id, 'appealstatus' => $status, 'timemodified' => $now,
        ]);
        (new audit_logger())->log('appeal.decided', (int) $appeal->companyid, (int) $appeal->sessionid,
            (int) $appeal->userid, ['appealId' => $appealid, 'status' => $status, 'decision' => $decision],
            $reviewerid, 'appeal', $appealid);
        $this->notify_user($appeal, $reviewerid);
        return $appeal;
    }

    /** Returns the latest appeal for a session. */
    public function get_for_session(int $sessionid): ?\stdClass {
        global $DB;
        $record = $DB->get_record_sql("SELECT * FROM {local_proctorcore_appeals}
            WHERE sessionid = :sessionid ORDER BY id DESC", ['sessionid' => $sessionid], IGNORE_MULTIPLE);
        return $record ?: null;
    }

    /**
     * Lists appeals the viewer is authorised to review.
     *
     * @param int $viewerid Reviewer user id.
     * @param array $filters Optional courseid, companyid, and status.
     * @param int $page Zero-based page.
     * @param int $perpage Page size.
     * @return array{records: array, total: int, page: int, perpage: int}
     */
    public function list_for_reviewer(
        int $viewerid,
        array $filters = [],
        int $page = 0,
        int $perpage = 25
    ): array {
        global $DB;

        $where = [];
        $params = [];
        if (!empty($filters['courseid'])) {
            $where[] = 's.courseid = :courseid';
            $params['courseid'] = (int) $filters['courseid'];
        }
        if (!empty($filters['companyid'])) {
            $where[] = 'a.companyid = :companyid';
            $params['companyid'] = (int) $filters['companyid'];
        }
        $status = strtolower((string) ($filters['status'] ?? ''));
        if ($status === 'pending') {
            $where[] = 'a.status IN (:holdpending, :submitted, :held)';
            $params += ['holdpending' => 'hold_pending', 'submitted' => 'submitted', 'held' => 'held'];
        } else if ($status !== '') {
            $where[] = 'a.status = :appealstatus';
            $params['appealstatus'] = clean_param($status, PARAM_ALPHANUMEXT);
        }
        $wheresql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT a.id AS appealid, a.sessionid, a.companyid, a.userid AS appellantid,
                       a.reason, a.details, a.status AS appealstatus, a.decision,
                       a.reviewerid, a.submittedat, a.decidedat,
                       s.cmid, s.courseid, s.quizid, s.attemptid, s.userid,
                       s.server_sessionid, s.result, s.status,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.email,
                       c.fullname AS coursename, q.name AS quizname,
                       qa.attempt AS attemptnumber
                  FROM {local_proctorcore_appeals} a
                  JOIN {local_proctorcore_sessions} s ON s.id = a.sessionid
                  JOIN {user} u ON u.id = a.userid
                  JOIN {course} c ON c.id = s.courseid
                  JOIN {quiz} q ON q.id = s.quizid
             LEFT JOIN {quiz_attempts} qa ON qa.id = s.attemptid
                  {$wheresql}
              ORDER BY a.submittedat DESC, a.id DESC";

        $reports = new report_service();
        $visible = [];
        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $record->id = (int) $record->sessionid;
            if (!$reports->can_review_appeal($record, $viewerid)) {
                continue;
            }
            $record->studentname = fullname($record);
            $visible[] = $record;
        }
        $page = max(0, $page);
        $perpage = min(100, max(1, $perpage));
        return [
            'records' => array_slice($visible, $page * $perpage, $perpage),
            'total' => count($visible),
            'page' => $page,
            'perpage' => $perpage,
        ];
    }

    /** Returns the number of unresolved appeals visible in one course. */
    public function count_pending_for_course(int $courseid, int $viewerid): int {
        $list = $this->list_for_reviewer($viewerid, ['courseid' => $courseid, 'status' => 'pending'], 0, 1);
        return (int) $list['total'];
    }

    /** Whether a learner can file an appeal now. */
    public function can_submit(\stdClass $session, int $userid): bool {
        global $DB;
        return (int) $session->userid === $userid && !empty($session->endedat)
            && !empty($session->appealuntil) && (int) $session->appealuntil >= time()
            && !$DB->record_exists_select('local_proctorcore_appeals',
                'sessionid = :sessionid AND status <> :withdrawn', ['sessionid' => (int) $session->id, 'withdrawn' => 'withdrawn']);
    }

    private function notify_reviewers(int $appealid): void {
        global $DB;
        $appeal = $DB->get_record('local_proctorcore_appeals', ['id' => $appealid], '*', MUST_EXIST);
        $session = (new session_repository())->get_by_id((int) $appeal->sessionid);
        $users = get_users_by_capability(\context_system::instance(), 'local/proctorcore:reviewappeals',
            'u.id,u.firstname,u.lastname,u.email,u.lang') ?: [];
        $coursecontext = \context_course::instance((int) $session->courseid);
        foreach (get_enrolled_users($coursecontext, 'moodle/course:manageactivities', 0,
            'u.id,u.firstname,u.lastname,u.email,u.lang') as $user) {
            $users[(int) $user->id] = $user;
        }
        if (!empty($session->cmid)) {
            $modulecontext = \context_module::instance((int) $session->cmid);
            foreach (get_users_by_capability($modulecontext, 'mod/quiz:viewreports',
                'u.id,u.firstname,u.lastname,u.email,u.lang') ?: [] as $user) {
                $users[(int) $user->id] = $user;
            }
        }
        $tenants = new tenant_resolver();
        $url = new \moodle_url('/local/proctorcore/appeal.php', ['sessionid' => (int) $appeal->sessionid]);
        foreach ($users as $user) {
            if ((int) $user->id === (int) $appeal->userid) {
                continue;
            }
            if (!is_siteadmin((int) $user->id)
                    && !$tenants->user_belongs_to_company((int) $user->id, (int) $appeal->companyid)) {
                continue;
            }
            $lang = clean_param((string) ($user->lang ?? 'en'), PARAM_LANG);
            $this->send_message($user,
                get_string('appeal:notificationsubject', 'local_proctorcore', null, $lang),
                get_string('appeal:notificationreviewer', 'local_proctorcore', $appealid, $lang), null, $url);
        }
    }

    private function notify_user(\stdClass $appeal, int $reviewerid): void {
        $user = \core_user::get_user((int) $appeal->userid, 'id,firstname,lastname,email,lang', MUST_EXIST);
        $lang = clean_param((string) ($user->lang ?? 'en'), PARAM_LANG);
        $this->send_message($user,
            get_string('appeal:decisionnotificationsubject', 'local_proctorcore', null, $lang),
            get_string('appeal:notificationdecision', 'local_proctorcore', [
                'status' => localised_value::value((string) $appeal->status, $lang),
                'decision' => $appeal->decision,
            ], $lang), $reviewerid);
    }

    private function send_message(
        \stdClass $recipient,
        string $subject,
        string $body,
        ?int $senderid = null,
        ?\moodle_url $contexturl = null
    ): void {
        $message = new \core\message\message();
        $message->component = 'local_proctorcore';
        $message->name = 'appeal_updates';
        $message->userfrom = $senderid ? \core_user::get_user($senderid, '*', MUST_EXIST) : \core_user::get_noreply_user();
        $message->userto = $recipient;
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $body;
        $message->notification = 1;
        if ($contexturl) {
            $message->contexturl = $contexturl->out(false);
            $message->contexturlname = get_string('appeal:view', 'local_proctorcore');
        }
        try {
            message_send($message);
        } catch (\Throwable $exception) {
            debugging('ProctorCore appeal notification failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
        }
    }

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
