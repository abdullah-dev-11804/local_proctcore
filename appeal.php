<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

use local_proctorcore\form\appeal_form;
use local_proctorcore\local\appeal_service;
use local_proctorcore\local\report_service;

require_login();
$sessionid = required_param('sessionid', PARAM_INT);
$reports = new report_service();
$report = $reports->get_session_report($sessionid, (int) $USER->id);
$session = $report['session'];
$context = context_course::instance((int) $session->courseid);
$service = new appeal_service();
$appeal = $service->get_for_session($sessionid);
$canreview = has_capability('local/proctorcore:reviewappeals', context_system::instance())
    && $reports->can_view_session($session, (int) $USER->id);

$PAGE->set_context($context);
$PAGE->set_url('/local/proctorcore/appeal.php', ['sessionid' => $sessionid]);
$PAGE->set_title(get_string('appeal:title', 'local_proctorcore'));
$PAGE->set_heading(get_string('appeal:title', 'local_proctorcore'));

if ($canreview && data_submitted() && optional_param('review', 0, PARAM_BOOL)) {
    require_sesskey();
    $service->decide((int) $appeal->id, (int) $USER->id,
        required_param('decisionstatus', PARAM_ALPHANUMEXT), required_param('decision', PARAM_TEXT));
    redirect($PAGE->url, get_string('appeal:decisionsaved', 'local_proctorcore'));
}

$form = null;
if (!$appeal && $service->can_submit($session, (int) $USER->id)) {
    $form = new appeal_form(null, ['sessionid' => $sessionid]);
    if ($data = $form->get_data()) {
        $service->submit($sessionid, (int) $USER->id, (string) $data->reason, (string) $data->details);
        redirect($PAGE->url, get_string('appeal:submitted', 'local_proctorcore'));
    }
}

echo $OUTPUT->header();
if ($appeal) {
    echo $OUTPUT->notification(get_string('appeal:currentstatus', 'local_proctorcore', s($appeal->status)),
        \core\output\notification::NOTIFY_INFO);
    echo html_writer::tag('p', format_text((string) $appeal->details, FORMAT_PLAIN));
    if ($appeal->decision) {
        echo html_writer::tag('h3', get_string('appeal:decision', 'local_proctorcore'));
        echo html_writer::tag('p', format_text((string) $appeal->decision, FORMAT_PLAIN));
    }
}
if ($form) {
    $form->display();
} else if (!$appeal) {
    echo $OUTPUT->notification(get_string('appeal:windowclosed', 'local_proctorcore'),
        \core\output\notification::NOTIFY_WARNING);
}
if ($appeal && $canreview && !in_array($appeal->status, ['approved', 'rejected'], true)) {
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'review', 'value' => 1]);
    echo html_writer::select(['approved' => get_string('appeal:approve', 'local_proctorcore'),
        'rejected' => get_string('appeal:reject', 'local_proctorcore')], 'decisionstatus');
    echo html_writer::tag('textarea', '', ['name' => 'decision', 'required' => 'required', 'rows' => 6, 'class' => 'form-control mt-3']);
    echo html_writer::tag('button', get_string('appeal:savedecision', 'local_proctorcore'),
        ['type' => 'submit', 'class' => 'btn btn-primary mt-3']);
    echo html_writer::end_tag('form');
}
echo html_writer::link(new moodle_url('/local/proctorcore/reports.php', ['sessionid' => $sessionid]),
    get_string('report:backtolist', 'local_proctorcore'), ['class' => 'btn btn-secondary mt-3']);
echo $OUTPUT->footer();
