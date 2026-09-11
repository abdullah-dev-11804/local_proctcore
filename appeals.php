<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/proctorcore:reviewappeals', $context);
$PAGE->set_context($context);
$PAGE->set_url('/local/proctorcore/appeals.php');
$PAGE->set_title(get_string('appeal:queue', 'local_proctorcore'));
$PAGE->set_heading(get_string('appeal:queue', 'local_proctorcore'));

$sql = "SELECT a.*, s.courseid, s.cmid, s.quizid, s.userid AS sessionuserid,
               u.firstname, u.lastname, c.fullname AS coursename, q.name AS quizname
          FROM {local_proctorcore_appeals} a
          JOIN {local_proctorcore_sessions} s ON s.id = a.sessionid
          JOIN {user} u ON u.id = a.userid
          JOIN {course} c ON c.id = s.courseid
          JOIN {quiz} q ON q.id = s.quizid
      ORDER BY CASE WHEN a.status IN ('submitted', 'hold_pending') THEN 0 ELSE 1 END,
               a.submittedat DESC";
$reports = new \local_proctorcore\local\report_service();
$table = new html_table();
$table->head = [get_string('report:student', 'local_proctorcore'), get_string('report:course', 'local_proctorcore'),
    get_string('report:quiz', 'local_proctorcore'), get_string('appeal:reason', 'local_proctorcore'),
    get_string('appeal:status', 'local_proctorcore'), get_string('actions')];
foreach ($DB->get_records_sql($sql) as $record) {
    if (!$reports->can_view_session((object) [
        'id' => $record->sessionid, 'userid' => $record->sessionuserid, 'companyid' => $record->companyid,
        'courseid' => $record->courseid, 'cmid' => $record->cmid, 'quizid' => $record->quizid,
    ], (int) $USER->id)) {
        continue;
    }
    $table->data[] = [fullname($record), format_string($record->coursename), format_string($record->quizname),
        s(str_replace('_', ' ', $record->reason)), s($record->status),
        html_writer::link(new moodle_url('/local/proctorcore/appeal.php', ['sessionid' => $record->sessionid]), get_string('view'))];
}
echo $OUTPUT->header();
echo html_writer::table($table);
echo $OUTPUT->footer();
