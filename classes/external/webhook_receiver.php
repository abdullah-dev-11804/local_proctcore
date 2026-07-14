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
            'payload' => $payload,
            'signature' => $signature,
        ]);

        // Workflow placeholder for signature validation, idempotency, and result updates.
        return ['accepted' => true, 'status' => 'received'];
    }

    public static function receive_returns(): external_single_structure {
        return new external_single_structure([
            'accepted' => new external_value(PARAM_BOOL, 'Whether the event was accepted.'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Processing status.'),
        ]);
    }
}
