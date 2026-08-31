<?php
// This file is part of Moodle - http://moodle.org/

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new moodle_exception('violation:invalidrequest', 'local_proctorcore');
    }
    if (empty($data['sesskey']) || !confirm_sesskey((string) $data['sesskey'])) {
        throw new moodle_exception('invalidsesskey');
    }
    $sessionid = (int) ($data['sessionId'] ?? 0);
    $action = clean_param((string) ($data['action'] ?? ''), PARAM_ALPHANUMEXT);
    if ($sessionid <= 0) {
        throw new moodle_exception('violation:invalidrequest', 'local_proctorcore');
    }

    $service = new \local_proctorcore\local\violation_service();
    if ($action === 'frame') {
        $result = $service->analyse_frame(
            $sessionid,
            (int) $USER->id,
            (string) ($data['frameImage'] ?? '')
        );
    } elseif ($action === 'event') {
        $result = $service->record_browser_event(
            $sessionid,
            (int) $USER->id,
            clean_param((string) ($data['eventType'] ?? ''), PARAM_ALPHANUMEXT),
            is_array($data['metadata'] ?? null) ? $data['metadata'] : []
        );
    } else {
        throw new moodle_exception('violation:invalidrequest', 'local_proctorcore');
    }

    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code($exception instanceof moodle_exception ? 400 : 500);
    debugging('ProctorCore monitor endpoint failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
    echo json_encode([
        'ok' => false,
        'error' => $exception instanceof moodle_exception
            ? $exception->errorcode
            : 'monitor_error',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
