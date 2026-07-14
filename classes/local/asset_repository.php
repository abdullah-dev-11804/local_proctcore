<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Owns report, video, snapshot, room scan, ID photo, and violation-act references.
 */
final class asset_repository {
    public const TABLE = 'local_proctorcore_assets';
}
