<?php
// This file is part of Moodle - http://moodle.org/

// Server-to-server endpoint: no browser session or Moodle login is required.
define('NO_MOODLE_COOKIES', true);
require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/**
 * Sends a JSON response and terminates the request.
 *
 * @param int $httpcode HTTP status.
 * @param array $body Response body.
 * @return void
 */
function local_proctorcore_webhook_respond(int $httpcode, array $body): void {
    http_response_code($httpcode);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    local_proctorcore_webhook_respond(405, [
        'accepted' => false,
        'status' => 'rejected',
        'error' => 'method_not_allowed',
    ]);
}

$rawpayload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PROCTORCORE_SIGNATURE']
    ?? $_SERVER['HTTP_X_PROCTORING_SIGNATURE']
    ?? '';

try {
    $processor = new \local_proctorcore\local\webhook_processor();
    $result = $processor->process((string) $rawpayload, (string) $signature);
    local_proctorcore_webhook_respond(200, $result);
} catch (\moodle_exception $exception) {
    // Use a controlled 4xx response; never expose stack traces or secrets.
    local_proctorcore_webhook_respond(400, [
        'accepted' => false,
        'status' => 'rejected',
        'error' => $exception->errorcode ?: 'invalid_webhook',
        'message' => get_string($exception->errorcode, $exception->module, $exception->a),
    ]);
} catch (\Throwable $exception) {
    debugging('ProctorCore webhook failure: ' . $exception->getMessage(), DEBUG_DEVELOPER);
    local_proctorcore_webhook_respond(500, [
        'accepted' => false,
        'status' => 'error',
        'error' => 'internal_error',
    ]);
}
