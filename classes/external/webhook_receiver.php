<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

/**
<<<<<<< HEAD
 * External API entry point for signed Server B webhook events.
 */
final class webhook_receiver extends external_api {
    public static function receive_parameters(): external_function_parameters {
        return new external_function_parameters([
            'payload' => new external_value(PARAM_RAW, 'Raw webhook payload JSON.'),
            'signature' => new external_value(PARAM_RAW, 'Webhook signature header value.'),
        ]);
    }

    public static function receive(string $payload, string $signature): array {
        self::validate_parameters(self::receive_parameters(), [
=======
 * Moodle external-API entry point for signed Server B webhook events.
 *
 * The public direct endpoint is /local/proctorcore/webhook.php. This external
 * function is retained for deployments that prefer Moodle web services.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class webhook_receiver extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function receive_parameters(): external_function_parameters {
        return new external_function_parameters([
            'payload' => new external_value(PARAM_RAW, 'Exact raw webhook payload JSON.'),
            'signature' => new external_value(PARAM_RAW, 'HMAC-SHA256 signature.'),
        ]);
    }

    /**
     * Receives and processes one event.
     *
     * @param string $payload Raw JSON.
     * @param string $signature HMAC signature.
     * @return array
     */
    public static function receive(string $payload, string $signature): array {
        $params = self::validate_parameters(self::receive_parameters(), [
>>>>>>> origin/danial
            'payload' => $payload,
            'signature' => $signature,
        ]);

<<<<<<< HEAD
        // Workflow placeholder for signature validation, idempotency, and result updates.
        return ['accepted' => true, 'status' => 'received'];
    }

    public static function receive_returns(): external_single_structure {
        return new external_single_structure([
            'accepted' => new external_value(PARAM_BOOL, 'Whether the event was accepted.'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Processing status.'),
=======
        $processor = new \local_proctorcore\local\webhook_processor();
        $result = $processor->process($params['payload'], $params['signature']);

        return [
            'accepted' => (bool) $result['accepted'],
            'status' => (string) $result['status'],
            'eventid' => (string) $result['eventid'],
            'sessionid' => (int) $result['sessionid'],
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function receive_returns(): external_single_structure {
        return new external_single_structure([
            'accepted' => new external_value(PARAM_BOOL, 'Whether the event was accepted.'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'processed or duplicate.'),
            'eventid' => new external_value(PARAM_ALPHANUMEXT, 'Server B event id.'),
            'sessionid' => new external_value(PARAM_INT, 'Local Moodle ProctorCore session id.'),
>>>>>>> origin/danial
        ]);
    }
}
