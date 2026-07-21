<?php
// This file is part of Moodle - http://moodle.org/

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../lib.php');

[$options, $unrecognised] = cli_get_params([
    'attemptid' => null,
    'help' => false,
], [
    'h' => 'help',
]);

if ($options['help'] || empty($options['attemptid'])) {
    echo "Repair an incomplete ProctorCore Server B binding.\n\n";
    echo "Usage:\n";
    echo "  php local/proctorcore/cli/repair_incomplete_session.php --attemptid=123\n";
    exit($options['help'] ? 0 : 1);
}

$attemptid = (int) $options['attemptid'];

try {
    $before = $DB->get_record('local_proctorcore_sessions', ['attemptid' => $attemptid]);
    echo "Before:\n";
    echo $before ? json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        : "No local session row yet.\n";

    $session = local_proctorcore_create_session_for_attempt($attemptid);

    echo "After:\n";
    echo json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    if (empty($session->server_sessionid)) {
        cli_error('Repair failed: server_sessionid is still empty.');
    }

    cli_writeln('Repair successful. Refresh the same Moodle Quiz attempt.');
} catch (Throwable $exception) {
    cli_error(get_class($exception) . ': ' . $exception->getMessage());
}
