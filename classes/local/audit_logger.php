<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Writes append-only administrator, coordinator, proctor, and integration audit events.
 */
final class audit_logger {
    public const TABLE = 'local_proctorcore_audit';
}
