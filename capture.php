<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $raw = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new moodle_exception('error:invalidcapturerequest', 'local_proctorcore');
    }

    $sesskey = clean_param((string) ($data['sesskey'] ?? ''), PARAM_ALPHANUM);
    if ($sesskey === '' || !confirm_sesskey($sesskey)) {
        throw new moodle_exception('invalidsesskey');
    }

    $action = clean_param((string) ($data['action'] ?? ''), PARAM_ALPHANUMEXT);
    $sessionid = (int) ($data['sessionId'] ?? 0);
    if ($sessionid <= 0) {
        throw new moodle_exception('error:invalidcapturerequest', 'local_proctorcore');
    }

    $service = new \local_proctorcore\local\capture_service();
    switch ($action) {
        case 'bootstrap':
            $result = $service->bootstrap($sessionid, (int) $USER->id);
            break;
        case 'start':
            $result = $service->start_capture(
                $sessionid,
                (int) $USER->id,
                clean_param((string) ($data['reason'] ?? 'attempt_page_connected'), PARAM_ALPHANUMEXT)
            );
            break;
        case 'snapshot':
            $violationid = isset($data['violationId']) ? (int) $data['violationId'] : null;
            $result = $service->request_snapshot(
                $sessionid,
                (int) $USER->id,
                clean_param((string) ($data['reason'] ?? ''), PARAM_ALPHANUMEXT),
                $violationid
            );
            break;
        case 'stop':
            $result = $service->stop_capture(
                $sessionid,
                (int) $USER->id,
                clean_param((string) ($data['reason'] ?? 'submitted'), PARAM_ALPHANUMEXT)
            );
            break;
        case 'media_failure':
            $result = $service->media_failure(
                $sessionid,
                (int) $USER->id,
                clean_param((string) ($data['reason'] ?? 'media_track_ended'), PARAM_ALPHANUMEXT),
                clean_param((string) ($data['message'] ?? ''), PARAM_TEXT)
            );
            break;
        default:
            throw new moodle_exception('error:invalidcaptureaction', 'local_proctorcore');
    }

    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code($exception instanceof moodle_exception ? 400 : 500);
    echo json_encode([
        'ok' => false,
        'error' => clean_param($exception->getMessage(), PARAM_TEXT),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
