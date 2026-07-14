<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Owns reads and writes for official proctoring session records.
 */
final class session_repository {
    public const TABLE = 'local_proctorcore_sessions';
}
