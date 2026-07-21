<?php
// This file is part of Moodle - http://moodle.org/

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'companyid' => 0,
    'help' => false,
], [
    'c' => 'companyid',
    'h' => 'help',
]);

if ($options['help']) {
    echo "Test the ProctorCore ML service health endpoint.\n\n";
    echo "php local/proctorcore/cli/test_ml_health.php [--companyid=0]\n";
    exit(0);
}

try {
    $companyid = max(0, (int) $options['companyid']);
    $config = (new \local_proctorcore\local\company_config_repository())->get_effective_config($companyid);
    echo "Company ID: {$companyid}\n";
    echo "ML URL: {$config->mlserviceurl}\n";
    echo "Verify SSL: " . (!empty($config->mlverifyssl) ? 'yes' : 'no') . "\n";
    $result = (new \local_proctorcore\local\ml_client($companyid))->health();
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (empty($result['ok']) && (($result['status'] ?? '') !== 'healthy')) {
        exit(2);
    }
    echo "ML HEALTH: OK\n";
} catch (Throwable $exception) {
    cli_error(get_class($exception) . ': ' . $exception->getMessage());
}
