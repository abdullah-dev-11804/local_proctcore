<?php
// This file is part of Moodle - http://moodle.org/

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'userid' => 0,
    'actorid' => 0,
    'reason' => '',
], [
    'h' => 'help',
    'u' => 'userid',
    'a' => 'actorid',
    'r' => 'reason',
]);

if ($unrecognised) {
    cli_error('Unknown options: ' . implode(', ', $unrecognised));
}

if ($options['help']) {
    echo "Reset a user's ProctorCore face reference.\n\n";
    echo "Options:\n";
    echo "  --userid=ID       Required Moodle user id to reset.\n";
    echo "  --actorid=ID      Required admin/actor Moodle user id for audit logging.\n";
    echo "  --reason=TEXT     Required reset reason.\n\n";
    echo "Example:\n";
    echo "  php local/proctorcore/cli/reset_face_reference.php --userid=25 --actorid=2 --reason=\"bad first capture\"\n";
    exit(0);
}

$userid = (int) $options['userid'];
$actorid = (int) $options['actorid'];
$reason = trim((string) $options['reason']);

if ($userid <= 0) {
    cli_error('Missing required --userid=ID.');
}
if ($actorid <= 0) {
    cli_error('Missing required --actorid=ID for audit logging.');
}
if ($reason === '') {
    cli_error('Missing required --reason=TEXT.');
}

$target = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,firstname,lastname,email', MUST_EXIST);
$actor = $DB->get_record('user', ['id' => $actorid, 'deleted' => 0], 'id,firstname,lastname,email', MUST_EXIST);

(new \local_proctorcore\local\identity_service())->reset_reference($userid, $reason, $actorid);

echo "Face reference reset.\n";
echo "User: {$target->id} " . fullname($target) . "\n";
echo "Actor: {$actor->id} " . fullname($actor) . "\n";
echo "Reason: {$reason}\n";
