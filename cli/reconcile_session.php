<?php
// This file is part of Moodle - http://moodle.org/

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'sessionid' => null,
    'resend' => false,
], ['h' => 'help']);
if ($options['help'] || $unrecognised || empty($options['sessionid'])) {
    echo "Reconcile Moodle evidence rows with the proctoring server.\n\n";
    echo "php local/proctorcore/cli/reconcile_session.php --sessionid=12 [--resend]\n";
    exit($options['help'] ? 0 : 1);
}

$session = (new \local_proctorcore\local\session_repository())->get_by_id((int) $options['sessionid']);
if (empty($session->server_sessionid)) {
    cli_error('The session has no proctoring-server id.');
}
$response = (new \local_proctorcore\local\server_client((int) $session->companyid))->reconcile_session(
    (string) $session->server_sessionid,
    !empty($options['resend'])
);
$result = $response['reconciliation'] ?? $response;
$available = array_flip(array_map('strval', $result['available'] ?? []));
$missing = array_flip(array_map('strval', $result['missing'] ?? []));
$assets = new \local_proctorcore\local\asset_repository();
foreach ($assets->get_for_session((int) $session->id) as $asset) {
    $externalid = (string) ($asset->externalid ?? '');
    if (isset($available[$externalid])) {
        $assets->set_status((int) $asset->id, 'active');
    } else if (isset($missing[$externalid])) {
        $assets->set_status((int) $asset->id, 'missing');
    }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
