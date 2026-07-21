<?php
// This file is part of Moodle - http://moodle.org/

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'method_not_allowed',
    ]);
    exit;
}

$sessionid = required_param('sessionid', PARAM_INT);
header('Content-Type: application/json; charset=utf-8');

try {
    $service = new \local_proctorcore\local\connection_recovery_service();
    $result = $service->heartbeat($sessionid, (int) $USER->id);
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $exception) {
    http_response_code($exception instanceof \required_capability_exception ? 403 : 400);
    echo json_encode([
        'ok' => false,
        'error' => $exception instanceof \moodle_exception
            ? $exception->errorcode
            : 'connection_recovery_error',
        'message' => clean_param($exception->getMessage(), PARAM_TEXT),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
