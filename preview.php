<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$cmid = required_param('cmid', PARAM_INT);
$cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('moodle/course:manageactivities', $context);

$PAGE->set_url(new moodle_url('/local/proctorcore/preview.php', ['cmid' => $cm->id]));
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_cm($cm);
$PAGE->set_title(get_string('preview:title', 'local_proctorcore'));
$PAGE->set_heading(format_string($quiz->name));
$PAGE->requires->css('/local/proctorcore/styles.css');

$config = (object) [
    'requirehttps' => 1,
    'requirecamera' => 1,
    'requiremicrophone' => 1,
    'requiresnapshot' => 1,
];
if (class_exists('\\quizaccess_proctorcore\\local\\settings_service')) {
    $config = (new \quizaccess_proctorcore\local\settings_service())
        ->get_effective_config((int) $quiz->id, (int) $course->id, (int) $USER->id);
}

$companyid = 0;
try {
    $companyid = local_proctorcore_get_user_companyid((int) $USER->id, (int) $course->id);
} catch (Throwable $exception) {
    $companyid = 0;
}

$companyconfig = (new \local_proctorcore\local\company_config_repository())
    ->get_effective_config($companyid);

$healthok = false;
$healthmessage = get_string('preview:serverunavailable', 'local_proctorcore');
try {
    $health = (new \local_proctorcore\local\integration_service())->health($companyid);
    $healthok = !empty($health['success']) || (($health['status'] ?? '') === 'healthy');
    $healthmessage = $healthok
        ? get_string('preview:serverhealthy', 'local_proctorcore')
        : get_string('preview:serverunavailable', 'local_proctorcore');
} catch (Throwable $exception) {
    $healthmessage = get_string('preview:servererror', 'local_proctorcore', $exception->getMessage());
}

$panelid = 'local-proctorcore-preview-panel';
$PAGE->requires->js_call_amd('local_proctorcore/precheck', 'init', [[
    'panelId' => $panelid,
    'previewMode' => true,
    'serverHealthy' => $healthok,
    'requireHttps' => !empty($config->requirehttps),
    'requireCamera' => !empty($config->requirecamera),
    'requireMicrophone' => !empty($config->requiremicrophone),
    'requireSnapshot' => !empty($config->requiresnapshot),
    'minimumSpeedMbps' => (float) $companyconfig->minimumspeedmbps,
    'minimumLighting' => (int) $companyconfig->minimumlighting,
    'pingUrl' => (new moodle_url('/local/proctorcore/precheck_ping.php'))->out(false),
    'strings' => [
        'checking' => get_string('precheck:checking', 'local_proctorcore'),
        'serverHealthy' => get_string('precheck:serverhealthy', 'local_proctorcore'),
        'serverUnavailable' => get_string('precheck:serverunavailable', 'local_proctorcore'),
        'passed' => get_string('precheck:passed', 'local_proctorcore'),
        'failed' => get_string('precheck:failed', 'local_proctorcore'),
        'running' => get_string('precheck:running', 'local_proctorcore'),
        'allPassed' => get_string('precheck:allpassed', 'local_proctorcore'),
        'someFailed' => get_string('precheck:somefailed', 'local_proctorcore'),
        'notRequired' => get_string('precheck:notrequired', 'local_proctorcore'),
        'browserUnsupported' => get_string('precheck:browserunsupported', 'local_proctorcore'),
        'secureRequired' => get_string('precheck:securerequired', 'local_proctorcore'),
        'networkOffline' => get_string('precheck:networkoffline', 'local_proctorcore'),
        'networkFailed' => get_string('precheck:networkfailed', 'local_proctorcore'),
        'mediaUnsupported' => get_string('precheck:mediaunsupported', 'local_proctorcore'),
        'permissionDenied' => get_string('precheck:permissiondenied', 'local_proctorcore'),
        'tooDark' => get_string('precheck:toodark', 'local_proctorcore'),
        'cameraRequiredFirst' => get_string('precheck:camerarequiredfirst', 'local_proctorcore'),
        'snapshotCaptured' => get_string('precheck:snapshotcaptured', 'local_proctorcore'),
    ],
]]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('preview:title', 'local_proctorcore'));
echo $OUTPUT->notification(get_string('preview:nosession', 'local_proctorcore'), 'info');
echo html_writer::div(
    s($healthmessage),
    'local-proctorcore-preview-health ' . ($healthok ? 'is-healthy' : 'is-unavailable')
);
echo local_proctorcore_render_precheck_panel($panelid, true);
echo $OUTPUT->single_button(
    new moodle_url('/mod/quiz/view.php', ['id' => $cm->id]),
    get_string('preview:returnquiz', 'local_proctorcore'),
    'get'
);
echo $OUTPUT->footer();
