<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

use local_proctorcore\local\audit_logger;
use local_proctorcore\local\report_service;
use local_proctorcore\output\report_renderer;

require_login();

$sessionid = optional_param('sessionid', 0, PARAM_INT);
$companyid = optional_param('companyid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$quizid = optional_param('quizid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$result = optional_param('result', '', PARAM_ALPHANUMEXT);
$status = optional_param('status', '', PARAM_ALPHANUMEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 25;

$service = new report_service();
$PAGE->set_pagelayout('report');
$PAGE->set_url(new moodle_url('/local/proctorcore/reports.php', array_filter([
    'sessionid' => $sessionid,
    'companyid' => $companyid,
    'courseid' => $courseid,
    'quizid' => $quizid,
    'userid' => $userid,
    'result' => $result,
    'status' => $status,
    'page' => $page,
])));

if ($sessionid > 0) {
    $report = $service->get_session_report($sessionid, (int) $USER->id);
    $session = $report['session'];
    $PAGE->set_context(context_course::instance((int) $session->courseid));
    $PAGE->set_title(get_string('report:title', 'local_proctorcore'));
    $PAGE->set_heading(format_string((string) $session->coursename));
    $PAGE->navbar->add(get_string('report:reports', 'local_proctorcore'),
        new moodle_url('/local/proctorcore/reports.php'));
    $PAGE->navbar->add(get_string('report:sessionnumber', 'local_proctorcore', (int) $session->id));

    (new audit_logger())->log(
        'report.viewed',
        (int) $session->companyid,
        (int) $session->id,
        (int) $session->userid,
        ['page' => 'detail'],
        (int) $USER->id,
        'session',
        (int) $session->id
    );

    $data = report_renderer::prepare_detail(
        $report,
        (int) $USER->id,
        $service->can_download_session($session, (int) $USER->id)
    );

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_proctorcore/report_summary', $data);
    echo $OUTPUT->footer();
    exit;
}

$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('report:reports', 'local_proctorcore'));
$PAGE->set_heading(get_string('report:reports', 'local_proctorcore'));

$list = $service->list_reports((int) $USER->id, $page, $perpage, [
    'companyid' => $companyid,
    'courseid' => $courseid,
    'quizid' => $quizid,
    'userid' => $userid,
    'result' => $result,
    'status' => $status,
]);
foreach ($list['records'] as $record) {
    $record->candownload = $service->can_download_session($record, (int) $USER->id);
}
$data = report_renderer::prepare_list($list['records']);

(new audit_logger())->log(
    'report.list_viewed',
    0,
    null,
    null,
    [
        'companyId' => $companyid,
        'courseId' => $courseid,
        'quizId' => $quizid,
        'userId' => $userid,
        'result' => $result,
        'status' => $status,
        'page' => $page,
    ],
    (int) $USER->id,
    'report_list',
    null
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('report:reports', 'local_proctorcore'));
echo $OUTPUT->render_from_template('local_proctorcore/report_list', $data);
echo $OUTPUT->paging_bar(
    (int) $list['total'],
    (int) $list['page'],
    (int) $list['perpage'],
    new moodle_url('/local/proctorcore/reports.php', array_filter([
        'companyid' => $companyid,
        'courseid' => $courseid,
        'quizid' => $quizid,
        'userid' => $userid,
        'result' => $result,
        'status' => $status,
    ]))
);
echo $OUTPUT->footer();
