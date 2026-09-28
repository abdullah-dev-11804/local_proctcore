<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/proctorcore:resetfaceenrolment', $context);

$userid = required_param('userid', PARAM_INT);
$action = optional_param('action', 'reset', PARAM_ALPHA);
$returntomanager = optional_param('returntomanager', 0, PARAM_BOOL);
$reason = optional_param('reason', '', PARAM_TEXT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
if ($action !== 'reset') {
    throw new invalid_parameter_exception('Only face-reference reset/re-enrol is available.');
}
$user = core_user::get_user($userid, '*', MUST_EXIST);
$enrollment = $DB->get_record('local_proctorcore_faceenrol', ['userid' => $userid], '*', MUST_EXIST);
if ((string) $enrollment->status !== 'active'
        || (string) ($enrollment->deletionstatus ?? 'none') !== 'none') {
    throw new moodle_exception('identity:referencenotactive', 'local_proctorcore');
}
$titlekey = 'identity:resettitle';

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/proctorcore/reset_face.php', [
    'userid' => $userid,
    'action' => $action,
    'returntomanager' => $returntomanager,
]));
$PAGE->set_title(get_string($titlekey, 'local_proctorcore'));
$PAGE->set_heading(get_string($titlekey, 'local_proctorcore'));

if ($confirm && confirm_sesskey()) {
    $service = new \local_proctorcore\local\identity_service();
    $service->reset_reference($userid, $reason, (int) $USER->id);
    $messagekey = 'identity:resetdone';
    $redirecturl = $returntomanager
        ? new moodle_url('/local/proctorcore/face_references.php')
        : new moodle_url('/user/profile.php', ['id' => $userid]);
    redirect(
        $redirecturl,
        get_string($messagekey, 'local_proctorcore'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string($titlekey, 'local_proctorcore'));
echo html_writer::tag('p', fullname($user));
echo $OUTPUT->notification(
    get_string('identity:resetwarning', 'local_proctorcore'),
    'warning',
    false
);
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out(false),
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => 1]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden', 'name' => 'returntomanager', 'value' => $returntomanager,
]);
echo html_writer::tag('label', get_string('identity:resetreason', 'local_proctorcore'), ['for' => 'id_reason']);
echo html_writer::empty_tag('br');
echo html_writer::tag('textarea', s($reason), [
    'id' => 'id_reason',
    'name' => 'reason',
    'rows' => 4,
    'cols' => 70,
    'required' => 'required',
]);
echo html_writer::empty_tag('br');
echo html_writer::tag('button', get_string(
    'identity:resetconfirm',
    'local_proctorcore'
), [
    'type' => 'submit',
    'class' => 'btn btn-danger mt-3',
]);
echo html_writer::end_tag('form');
if ($returntomanager) {
    echo html_writer::link(
        new moodle_url('/local/proctorcore/face_references.php'),
        get_string('cancel'),
        ['class' => 'btn btn-secondary mt-3 ml-2']
    );
}
echo $OUTPUT->footer();
