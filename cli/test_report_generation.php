<?php
// This file is part of Moodle - http://moodle.org/

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'sessionid' => 0,
    'help' => false,
], [
    's' => 'sessionid',
    'h' => 'help',
]);

if ($options['help'] || empty($options['sessionid'])) {
    echo "Generate and inspect a Section 3.1 report.\n\n";
    echo "php local/proctorcore/cli/test_report_generation.php --sessionid=25\n";
    exit($options['help'] ? 0 : 1);
}

$sessionid = (int) $options['sessionid'];
try {
    $reports = new \local_proctorcore\local\report_service();
    $data = $reports->get_session_report_for_generation($sessionid);
    $asset = (new \local_proctorcore\local\report_pdf_service())
        ->generate_and_store($sessionid, null, 'cli_test');
    $file = (new \local_proctorcore\local\asset_access_service())->get_moodle_file($asset);

    echo "REPORT GENERATION: OK\n";
    echo "Session: {$sessionid}\n";
    echo "Result: {$data['session']->result}\n";
    echo "Violations: " . count($data['violations']) . "\n";
    echo "Identity snapshots: " . count($data['assets']['identity']) . "\n";
    echo "Violation snapshots: " . count($data['assets']['violations']) . "\n";
    echo "Submission snapshots: " . count($data['assets']['submission']) . "\n";
    echo "Video clips: " . count($data['assets']['videos']) . "\n";
    echo "Report asset id: {$asset->id}\n";
    echo "PDF filename: {$file->get_filename()}\n";
    echo "PDF size: {$file->get_filesize()} bytes\n";
    echo "Expires: " . userdate((int) $asset->expiresat) . "\n";
    echo "Open: {$CFG->wwwroot}/local/proctorcore/reports.php?sessionid={$sessionid}\n";
} catch (Throwable $exception) {
    echo "REPORT GENERATION: FAILED\n";
    echo get_class($exception) . ': ' . $exception->getMessage() . "\n";
    echo $exception->getFile() . ':' . $exception->getLine() . "\n";
    exit(1);
}
