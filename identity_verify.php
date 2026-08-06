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
        throw new moodle_exception('identity:invalidrequest', 'local_proctorcore');
    }
    if (empty($data['sesskey']) || !confirm_sesskey((string) $data['sesskey'])) {
        throw new moodle_exception('invalidsesskey');
    }
    $quizid = (int) ($data['quizId'] ?? 0);
    $token = clean_param((string) ($data['token'] ?? ''), PARAM_ALPHANUM);
    if ($quizid <= 0 || $token === '') {
        throw new moodle_exception('identity:invalidrequest', 'local_proctorcore');
    }

    $result = (new \local_proctorcore\local\identity_service())->verify_preflight(
        $quizid,
        (int) $USER->id,
        $token,
        $data['centerImages'] ?? ($data['centerImage'] ?? ''),
        $data['leftImages'] ?? ($data['leftImage'] ?? ''),
        $data['rightImages'] ?? ($data['rightImage'] ?? ''),
        (string) ($data['confirmedName'] ?? ''),
        !empty($data['confirmEnrollment'])
    );
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code($exception instanceof moodle_exception ? 400 : 500);
    debugging('ProctorCore identity endpoint failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
    echo json_encode([
        'ok' => false,
        'passed' => false,
        'error' => $exception instanceof moodle_exception
            ? $exception->errorcode
            : 'identity_error',
        'message' => clean_param($exception->getMessage(), PARAM_TEXT),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
