<?php
// This file is part of Moodle - http://moodle.org/

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'sessionid' => 0,
    'type' => 'tab_hidden',
    'help' => false,
], [
    's' => 'sessionid',
    't' => 'type',
    'h' => 'help',
]);

if ($options['help'] || (int) $options['sessionid'] <= 0) {
    echo "Create a browser-type violation against an active test session.\n\n";
    echo "php local/proctorcore/cli/test_violation_event.php --sessionid=25 --type=tab_hidden\n";
    echo "Types: tab_hidden, window_blur, camera_ended, microphone_ended, camera_blocked\n";
    exit($options['help'] ? 0 : 1);
}

try {
    $session = (new \local_proctorcore\local\session_repository())
        ->get_by_id((int) $options['sessionid']);
    $result = (new \local_proctorcore\local\violation_service())->record_browser_event(
        (int) $session->id,
        (int) $session->userid,
        clean_param((string) $options['type'], PARAM_ALPHANUMEXT),
        ['cliTest' => true, 'testedAt' => time()]
    );
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    echo "VIOLATION EVENT TEST: OK\n";
} catch (Throwable $exception) {
    cli_error(get_class($exception) . ': ' . $exception->getMessage());
}
