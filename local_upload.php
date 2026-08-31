<?php
// This file is part of Moodle - http://moodle.org/

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $sesskey = required_param('sesskey', PARAM_ALPHANUM);
    if (!confirm_sesskey($sesskey)) {
        throw new moodle_exception('invalidsesskey');
    }
    if (!\local_proctorcore\local\local_capture_storage::is_enabled()) {
        throw new moodle_exception('error:localstorageinvalid', 'local_proctorcore');
    }

    $sessionid = required_param('sessionid', PARAM_INT);
    $kind = required_param('kind', PARAM_ALPHANUMEXT);
    $reason = optional_param('reason', '', PARAM_ALPHANUMEXT);
    $segment = optional_param('segment', 1, PARAM_INT);
    $sequence = optional_param('sequence', 0, PARAM_INT);
    $violationid = optional_param('violationid', 0, PARAM_INT);

    $repository = new \local_proctorcore\local\session_repository();
    $session = $repository->get_by_id($sessionid);
    if ((int) $session->userid !== (int) $USER->id) {
        throw new moodle_exception('error:sessionowner', 'local_proctorcore');
    }
    if (!in_array((string) $session->status, ['active', 'interrupted'], true)
            || (string) $session->techcheckstatus !== 'passed') {
        throw new moodle_exception('error:captureclosed', 'local_proctorcore');
    }
    if (empty($_FILES['asset'])) {
        throw new moodle_exception('error:localuploadfailed', 'local_proctorcore');
    }

    $result = (new \local_proctorcore\local\local_capture_storage())->save_upload(
        $session,
        $_FILES['asset'],
        $kind,
        $reason,
        max(1, $segment),
        max(0, $sequence),
        $violationid > 0 ? $violationid : null,
        (int) $USER->id
    );

    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code($exception instanceof moodle_exception ? 400 : 500);
    debugging('ProctorCore local upload endpoint failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
    echo json_encode([
        'ok' => false,
        'error' => $exception instanceof moodle_exception
            ? $exception->errorcode
            : 'local_upload_error',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
