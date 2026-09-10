<?php
// This file is part of Moodle - http://moodle.org/

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'execute' => false,
    'confirm' => '',
], [
    'h' => 'help',
]);

if ($unrecognised) {
    cli_error('Unknown options: ' . implode(', ', $unrecognised));
}

$confirmation = 'DELETE-ALL-PROCTORCORE-TEST-DATA';
$tables = [
    'local_proctorcore_assets',
    'local_proctorcore_appeals',
    'local_proctorcore_webhooks',
    'local_proctorcore_checks',
    'local_proctorcore_rulesack',
    'local_proctorcore_fieldvals',
    'local_proctorcore_violations',
    'local_proctorcore_audit',
    'local_proctorcore_sessions',
    'local_proctorcore_faceenrol',
];
$preservedtables = [
    'local_proctorcore_companycfg',
    'local_proctorcore_quizcfg',
    'local_proctorcore_fields',
];

if ($options['help']) {
    echo "Purge pre-production ProctorCore test data.\n\n";
    echo "Without --execute, the command only displays record counts.\n";
    echo "Company policies, quiz configuration, and participant field definitions are preserved.\n";
    echo "Moodle quiz attempts are not deleted.\n\n";
    echo "  php local/proctorcore/cli/purge_test_data.php\n";
    echo "  php local/proctorcore/cli/purge_test_data.php --execute --confirm={$confirmation}\n";
    exit(0);
}

$manager = $DB->get_manager();
$counts = [];
foreach (array_merge($tables, $preservedtables) as $table) {
    if (!$manager->table_exists(new xmldb_table($table))) {
        $counts[$table] = null;
        continue;
    }
    $counts[$table] = $DB->count_records($table);
}

$context = context_system::instance();
$files = get_file_storage()->get_area_files(
    $context->id,
    'local_proctorcore',
    'reports',
    false,
    'id',
    false
);

echo "ProctorCore pre-production data purge\n";
echo "=====================================\n";
foreach ($tables as $table) {
    $count = $counts[$table];
    echo sprintf("Delete   %-40s %s\n", $table, $count === null ? 'table missing' : $count . ' row(s)');
}
echo sprintf("Delete   %-40s %d file(s)\n", 'Moodle report file area', count($files));
foreach ($preservedtables as $table) {
    $count = $counts[$table];
    echo sprintf("Preserve %-40s %s\n", $table, $count === null ? 'table missing' : $count . ' row(s)');
}

if (!$options['execute']) {
    echo "\nDry run only. No records or files were changed.\n";
    exit(0);
}

if (!hash_equals($confirmation, (string) $options['confirm'])) {
    cli_error("Refusing to purge. Supply --confirm={$confirmation}");
}

$transaction = $DB->start_delegated_transaction();
try {
    foreach ($tables as $table) {
        if ($manager->table_exists(new xmldb_table($table))) {
            $DB->delete_records($table);
        }
    }
    $transaction->allow_commit();
} catch (Throwable $exception) {
    $transaction->rollback($exception);
}

get_file_storage()->delete_area_files($context->id, 'local_proctorcore', 'reports');

echo "\nPurge completed. ProctorCore configuration was preserved.\n";
echo "Delete test quiz attempts separately through Moodle's quiz Results page when required.\n";

