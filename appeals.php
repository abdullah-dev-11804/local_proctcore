<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

use local_proctorcore\local\appeal_service;
use local_proctorcore\output\appeal_renderer;

require_login();

$courseid = optional_param('courseid', 0, PARAM_INT);
$companyid = optional_param('companyid', 0, PARAM_INT);
$status = optional_param('status', 'pending', PARAM_ALPHANUMEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 25;
$systemcontext = context_system::instance();
$context = $systemcontext;
$canaccess = has_capability('local/proctorcore:reviewappeals', $systemcontext);
if ($courseid > 0) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($courseid);
    $canaccess = (new \local_proctorcore\local\report_service())
        ->can_review_course_appeals($courseid, (int) $USER->id);
}
if (!$canaccess) {
    throw new required_capability_exception($context, 'local/proctorcore:reviewappeals', 'nopermissions', '');
}

$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_url(new moodle_url('/local/proctorcore/appeals.php', array_filter([
    'courseid' => $courseid,
    'companyid' => $companyid,
    'status' => $status,
    'page' => $page,
])));
$PAGE->set_title(get_string('appeal:queue', 'local_proctorcore'));
$PAGE->set_heading(get_string('appeal:queue', 'local_proctorcore'));

$service = new appeal_service();
$list = $service->list_for_reviewer((int) $USER->id, [
    'courseid' => $courseid,
    'companyid' => $companyid,
    'status' => $status,
], $page, $perpage);
$data = appeal_renderer::prepare_list($list['records']);

$baseparams = $courseid > 0 ? ['courseid' => $courseid] : [];
$tabs = [
    new tabobject('reports', new moodle_url('/local/proctorcore/reports.php', $baseparams),
        get_string('report:reports', 'local_proctorcore')),
    new tabobject('appeals', new moodle_url('/local/proctorcore/appeals.php', $baseparams),
        get_string('appeal:queue', 'local_proctorcore')),
];

echo $OUTPUT->header();
echo $OUTPUT->tabtree($tabs, 'appeals');
echo $OUTPUT->heading(get_string('appeal:queue', 'local_proctorcore'));
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-3']);
if ($courseid > 0) {
    echo html_writer::empty_tag('input', [
        'type' => 'hidden', 'name' => 'courseid', 'value' => $courseid,
    ]);
}
echo html_writer::select([
    'pending' => get_string('appeal:pending', 'local_proctorcore'),
    'submitted' => get_string('appeal:submittedstatus', 'local_proctorcore'),
    'approved' => get_string('appeal:approved', 'local_proctorcore'),
    'rejected' => get_string('appeal:rejected', 'local_proctorcore'),
    '' => get_string('appeal:all', 'local_proctorcore'),
], 'status', $status, false, ['class' => 'custom-select w-auto mr-2']);
echo html_writer::tag('button', get_string('applyfilters'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');
echo $OUTPUT->render_from_template('local_proctorcore/appeal_list', $data);
echo $OUTPUT->paging_bar(
    (int) $list['total'],
    (int) $list['page'],
    (int) $list['perpage'],
    new moodle_url('/local/proctorcore/appeals.php', array_filter([
        'courseid' => $courseid, 'companyid' => $companyid, 'status' => $status,
    ]))
);
echo $OUTPUT->footer();
