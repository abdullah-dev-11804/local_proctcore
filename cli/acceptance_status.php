<?php
// This file is part of Moodle - http://moodle.org/

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'sessionid' => 0,
    'help' => false,
], ['s' => 'sessionid', 'h' => 'help']);

if ($options['help']) {
    echo "Show ProctorCore acceptance-test readiness and an optional session summary.\n";
    echo "php local/proctorcore/cli/acceptance_status.php [--sessionid=25]\n";
    exit(0);
}

$dbman = $DB->get_manager();
$tables = [
    'local_proctorcore_sessions',
    'local_proctorcore_checks',
    'local_proctorcore_violations',
    'local_proctorcore_assets',
    'local_proctorcore_audit',
    'local_proctorcore_webhooks',
];
foreach ($tables as $name) {
    $exists = $dbman->table_exists(new xmldb_table($name));
    echo str_pad($name, 34) . ': ' . ($exists ? 'OK' : 'MISSING') . PHP_EOL;
}

$classes = [
    '\\local_proctorcore\\local\\identity_service',
    '\\local_proctorcore\\local\\violation_service',
    '\\local_proctorcore\\local\\report_service',
    '\\local_proctorcore\\local\\retention_policy',
    '\\local_proctorcore\\local\\integration_service',
    '\\local_proctorcore\\local\\connection_recovery_service',
];
foreach ($classes as $class) {
    echo str_pad($class, 58) . ': ' . (class_exists($class) ? 'OK' : 'MISSING') . PHP_EOL;
}

$config = (new \local_proctorcore\local\company_config_repository())->get_effective_config(0);
echo PHP_EOL . 'Configuration' . PHP_EOL;
echo 'integration enabled: ' . (!empty($config->enabled) ? 'yes' : 'no') . PHP_EOL;
echo 'identity enabled: ' . (!empty($config->identityenabled) ? 'yes' : 'no') . PHP_EOL;
echo 'monitoring enabled: ' . (!empty($config->monitoringenabled) ? 'yes' : 'no') . PHP_EOL;
echo 'ML service URL: ' . ($config->mlserviceurl ?: '(not configured)') . PHP_EOL;
echo 'report retention days: ' . (int) $config->reportretentiondays . PHP_EOL;

$sessionid = (int) $options['sessionid'];
if ($sessionid > 0) {
    echo PHP_EOL . 'Session' . PHP_EOL;
    $session = $DB->get_record('local_proctorcore_sessions', ['id' => $sessionid], '*', MUST_EXIST);
    $summary = [
        'id' => (int) $session->id,
        'attemptId' => (int) $session->attemptid,
        'status' => (string) $session->status,
        'result' => (string) $session->result,
        'techCheck' => (string) $session->techcheckstatus,
        'identity' => (string) $session->identitystatus,
        'violations' => (int) $session->violationcount,
        'riskScore' => (int) $session->risk_score,
        'snapshots' => (int) $session->snapshotcount,
        'lastHeartbeat' => (int) $session->lastheartbeat,
        'reportExpiresAt' => (int) $session->reportexpiresat,
    ];
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
