<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

$sessionid = required_param('sessionid', PARAM_INT);
require_login();

$session = (new \local_proctorcore\local\session_repository())->get_by_id($sessionid);
if ((int) $session->userid !== (int) $USER->id) {
    throw new moodle_exception('error:sessionowner', 'local_proctorcore');
}

$PAGE->set_url(new moodle_url('/local/proctorcore/screen_capture.php', ['sessionid' => $sessionid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('capture:screenwindowtitle', 'local_proctorcore'));
$PAGE->set_heading(get_string('capture:screenwindowtitle', 'local_proctorcore'));
$PAGE->requires->css('/local/proctorcore/styles.css');
$PAGE->requires->js_call_amd('local_proctorcore/screen_capture', 'init', [[
    'sessionId' => $sessionid,
    'endpoint' => (new moodle_url('/local/proctorcore/capture.php'))->out(false),
    'monitorEndpoint' => (new moodle_url('/local/proctorcore/monitor.php'))->out(false),
    'sesskey' => sesskey(),
    'strings' => [
        'title' => get_string('capture:screenwindowtitle', 'local_proctorcore'),
        'instructions' => get_string('capture:screeninstructions', 'local_proctorcore'),
        'unsupported' => get_string('capture:screenunsupported', 'local_proctorcore'),
        'denied' => get_string('capture:screendenied', 'local_proctorcore'),
        'incomplete' => get_string('capture:screenincomplete', 'local_proctorcore'),
        'stopped' => get_string('capture:screenstopped', 'local_proctorcore'),
        'active' => get_string('capture:screenactive', 'local_proctorcore'),
        'activeFallback' => get_string('capture:screenactivefallback', 'local_proctorcore'),
        'connecting' => get_string('capture:screenconnecting', 'local_proctorcore'),
        'start' => get_string('capture:startscreen', 'local_proctorcore'),
        'stop' => get_string('capture:stopscreen', 'local_proctorcore'),
        'failed' => get_string('capture:failed', 'local_proctorcore'),
    ],
]]);

echo $OUTPUT->header();
echo html_writer::start_tag('main', ['class' => 'local-proctorcore-screen-controller']);
echo html_writer::tag('h2', get_string('capture:screenwindowtitle', 'local_proctorcore'));
echo html_writer::tag('p', get_string('capture:screeninstructions', 'local_proctorcore'), [
    'data-screen-instructions' => '1',
]);
echo html_writer::tag('div', get_string('capture:screenpending', 'local_proctorcore'), [
    'class' => 'alert alert-warning',
    'role' => 'status',
    'data-screen-status' => '1',
]);
echo html_writer::tag('video', '', [
    'autoplay' => 'autoplay',
    'muted' => 'muted',
    'playsinline' => 'playsinline',
    'hidden' => 'hidden',
    'data-screen-preview' => '1',
]);
echo html_writer::start_div('local-proctorcore-screen-actions');
echo html_writer::tag('button', get_string('capture:startscreen', 'local_proctorcore'), [
    'type' => 'button',
    'class' => 'btn btn-primary',
    'data-screen-start' => '1',
]);
echo html_writer::tag('button', get_string('capture:stopscreen', 'local_proctorcore'), [
    'type' => 'button',
    'class' => 'btn btn-secondary',
    'hidden' => 'hidden',
    'data-screen-stop' => '1',
]);
echo html_writer::end_div();
echo html_writer::end_tag('main');
echo $OUTPUT->footer();
