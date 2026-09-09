<?php
// This file is part of Moodle - http://moodle.org/

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_proctorcore\local\server_client;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'sessionid' => 0,
    'attemptid' => 0,
    'server' => false,
], [
    'h' => 'help',
    's' => 'sessionid',
    'a' => 'attemptid',
]);

if ($unrecognised) {
    cli_error('Unknown options: ' . implode(', ', $unrecognised));
}

if ($options['help'] || ((int) $options['sessionid'] <= 0 && (int) $options['attemptid'] <= 0)) {
    echo "Debug ProctorCore report evidence for a Moodle session or quiz attempt.\n\n";
    echo "Options:\n";
    echo "  --sessionid=ID   Local ProctorCore session id.\n";
    echo "  --attemptid=ID   Moodle quiz attempt id. Uses the newest matching ProctorCore session.\n";
    echo "  --server         Also ask Server B for its session asset registry.\n\n";
    echo "Examples:\n";
    echo "  php local/proctorcore/cli/debug_report_assets.php --sessionid=12\n";
    echo "  php local/proctorcore/cli/debug_report_assets.php --attemptid=170 --server\n";
    exit(0);
}

$session = null;
if ((int) $options['sessionid'] > 0) {
    $session = $DB->get_record('local_proctorcore_sessions', ['id' => (int) $options['sessionid']], '*', MUST_EXIST);
} else {
    $records = $DB->get_records(
        'local_proctorcore_sessions',
        ['attemptid' => (int) $options['attemptid']],
        'id DESC',
        '*',
        0,
        1
    );
    $session = $records ? reset($records) : null;
    if (!$session) {
        cli_error('No ProctorCore session found for attempt id ' . (int) $options['attemptid'] . '.');
    }
}

echo "Session\n";
echo json_encode([
    'id' => (int) $session->id,
    'companyId' => (int) $session->companyid,
    'userId' => (int) $session->userid,
    'attemptId' => (int) $session->attemptid,
    'serverSessionId' => (string) $session->server_sessionid,
    'status' => (string) $session->status,
    'result' => (string) $session->result,
    'violationCount' => (int) $session->violationcount,
    'snapshotCount' => (int) $session->snapshotcount,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL . PHP_EOL;

$assets = $DB->get_records(
    'local_proctorcore_assets',
    ['sessionid' => (int) $session->id],
    'timecreated ASC',
    'id,assettype,storage,status,externalid,violationid,mime,filesize,availableat,expiresat,deletedat,metadata'
);

echo "Moodle asset rows: " . count($assets) . PHP_EOL;
foreach ($assets as $asset) {
    $metadata = json_decode((string) ($asset->metadata ?? ''), true);
    $reason = is_array($metadata) ? ($metadata['reason'] ?? null) : null;
    echo json_encode([
        'id' => (int) $asset->id,
        'type' => (string) $asset->assettype,
        'storage' => (string) $asset->storage,
        'status' => (string) $asset->status,
        'externalId' => (string) $asset->externalid,
        'violationId' => $asset->violationid !== null ? (int) $asset->violationid : null,
        'reason' => $reason,
        'mime' => (string) $asset->mime,
        'sizeBytes' => $asset->filesize !== null ? (int) $asset->filesize : null,
        'deletedAt' => $asset->deletedat !== null ? (int) $asset->deletedat : null,
        'expiresAt' => $asset->expiresat !== null ? (int) $asset->expiresat : null,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$webhooks = $DB->get_records_select(
    'local_proctorcore_webhooks',
    'sessionid = :sessionid AND (eventtype = :asset OR eventtype = :completed OR eventtype = :failed)',
    [
        'sessionid' => (int) $session->id,
        'asset' => 'asset.captured',
        'completed' => 'session.completed',
        'failed' => 'session.failed',
    ],
    'id DESC',
    'id,eventid,eventtype,status,attempts,lasterror,receivedat,processedat',
    0,
    30
);

echo PHP_EOL . "Recent Moodle webhook rows: " . count($webhooks) . PHP_EOL;
foreach ($webhooks as $webhook) {
    echo json_encode([
        'id' => (int) $webhook->id,
        'eventId' => (string) $webhook->eventid,
        'eventType' => (string) $webhook->eventtype,
        'status' => (string) $webhook->status,
        'attempts' => (int) $webhook->attempts,
        'receivedAt' => $webhook->receivedat !== null ? (int) $webhook->receivedat : null,
        'processedAt' => $webhook->processedat !== null ? (int) $webhook->processedat : null,
        'error' => (string) ($webhook->lasterror ?? ''),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

if (!empty($options['server'])) {
    echo PHP_EOL . "Server B session registry\n";
    if (trim((string) $session->server_sessionid) === '') {
        echo "No server_sessionid is stored on this Moodle session.\n";
        exit(0);
    }

    try {
        $response = (new server_client((int) $session->companyid))->get_session((string) $session->server_sessionid);
        $serversession = $response['session'] ?? [];
        $serverassets = is_array($serversession['assets'] ?? null) ? $serversession['assets'] : [];
        echo "Server B asset entries: " . count($serverassets) . PHP_EOL;
        foreach ($serverassets as $asset) {
            echo json_encode([
                'assetId' => (string) ($asset['assetId'] ?? ''),
                'type' => (string) ($asset['type'] ?? ''),
                'reason' => (string) ($asset['reason'] ?? ''),
                'path' => (string) ($asset['path'] ?? ''),
                'mimeType' => (string) ($asset['mimeType'] ?? ''),
                'sizeBytes' => isset($asset['sizeBytes']) ? (int) $asset['sizeBytes'] : null,
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        }
    } catch (Throwable $exception) {
        echo "Server B lookup failed: " . $exception->getMessage() . PHP_EOL;
    }
}
