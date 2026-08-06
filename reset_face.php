<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/proctorcore:resetfaceenrolment', $context);

$userid = required_param('userid', PARAM_INT);
$reason = optional_param('reason', '', PARAM_TEXT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$user = core_user::get_user($userid, '*', MUST_EXIST);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/proctorcore/reset_face.php', ['userid' => $userid]));
$PAGE->set_title(get_string('identity:resettitle', 'local_proctorcore'));
$PAGE->set_heading(get_string('identity:resettitle', 'local_proctorcore'));

if ($confirm && confirm_sesskey()) {
    (new \local_proctorcore\local\identity_service())->reset_reference($userid, $reason, (int) $USER->id);
    redirect(
        new moodle_url('/user/profile.php', ['id' => $userid]),
        get_string('identity:resetdone', 'local_proctorcore'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('identity:resettitle', 'local_proctorcore'));
echo html_writer::tag('p', fullname($user));
echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $PAGE->url->out(false),
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'confirm', 'value' => 1]);
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
echo html_writer::tag('button', get_string('identity:resetconfirm', 'local_proctorcore'), [
    'type' => 'submit',
    'class' => 'btn btn-danger mt-3',
]);
echo html_writer::end_tag('form');
echo $OUTPUT->footer();
