<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

$attemptid = required_param('attemptid', PARAM_INT);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/proctorcore/reconnect.php', [
    'attemptid' => $attemptid,
    'sesskey' => sesskey(),
]));
$PAGE->set_title(get_string('reconnect:title', 'local_proctorcore'));
$PAGE->set_heading(get_string('reconnect:title', 'local_proctorcore'));

$service = new \local_proctorcore\local\connection_recovery_service();
$result = $service->resume_attempt($attemptid, (int) $USER->id);

redirect(
    $result['attemptUrl'],
    get_string('reconnect:success', 'local_proctorcore'),
    0,
    \core\output\notification::NOTIFY_SUCCESS
);
