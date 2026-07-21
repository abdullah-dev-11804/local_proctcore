<?php
// This file is part of Moodle - http://moodle.org/

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/proctorcore/lib.php');

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'attemptid' => 0,
    'health' => false,
], [
    'h' => 'help',
    'a' => 'attemptid',
]);

if ($unrecognised) {
    cli_error('Unknown options: ' . implode(', ', $unrecognised));
}

if ($options['help']) {
    echo "ProctorCore diagnostic\n\n";
    echo "  --health              Check Server B health for company 0.\n";
    echo "  --attemptid=ID        Inspect/create the session for a Quiz attempt.\n";
    exit(0);
}

$result = [
    'component' => 'local_proctorcore',
    'version' => get_config('local_proctorcore', 'version'),
    'methods' => [],
    'tables' => [],
    'settings' => [
        'enabled' => (bool) get_config('local_proctorcore', 'enabled'),
        'serverbaseurl' => (string) get_config('local_proctorcore', 'serverbaseurl'),
        'verifyssl' => (bool) get_config('local_proctorcore', 'verifyssl'),
        'heartbeatintervalsecs' => (int) get_config('local_proctorcore', 'heartbeatintervalsecs'),
        'heartbeatgracesecs' => (int) get_config('local_proctorcore', 'heartbeatgracesecs'),
        'defaultresumewindowsecs' => (int) get_config('local_proctorcore', 'defaultresumewindowsecs'),
    ],
];

$checks = [
    ['\\local_proctorcore\\local\\session_repository', 'get_by_attempt_and_user'],
    ['\\local_proctorcore\\local\\session_repository', 'apply_final_result'],
    ['\\local_proctorcore\\local\\server_client', 'start_session'],
    ['\\local_proctorcore\\local\\server_client', 'resume_session'],
    ['\\local_proctorcore\\local\\connection_recovery_service', 'resume_attempt'],
    ['\\local_proctorcore\\local\\webhook_processor', 'process'],
];
foreach ($checks as [$class, $method]) {
    $result['methods'][$class . '::' . $method] = class_exists($class) && method_exists($class, $method);
}

foreach (['local_proctorcore_sessions', 'local_proctorcore_quizcfg',
        'local_proctorcore_webhooks', 'local_proctorcore_audit'] as $table) {
    $result['tables'][$table] = $DB->get_manager()->table_exists(new xmldb_table($table));
}

if (!empty($options['health'])) {
    try {
        $result['serverHealth'] = (new \local_proctorcore\local\integration_service())->health(0);
    } catch (Throwable $exception) {
        $result['serverHealth'] = [
            'ok' => false,
            'error' => get_class($exception),
            'message' => $exception->getMessage(),
        ];
    }
}

$attemptid = (int) $options['attemptid'];
if ($attemptid > 0) {
    try {
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $session = local_proctorcore_create_session_for_attempt($attemptid);
        $result['attempt'] = [
            'id' => (int) $attempt->id,
            'quizid' => (int) $attempt->quiz,
            'userid' => (int) $attempt->userid,
            'state' => (string) $attempt->state,
            'preview' => (bool) $attempt->preview,
            'timestart' => (int) $attempt->timestart,
        ];
        $result['session'] = $session;
    } catch (Throwable $exception) {
        $result['attemptError'] = [
            'error' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
