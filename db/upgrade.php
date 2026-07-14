<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Handles schema/data upgrades for ProctorCore.
 *
 * @param int $oldversion Installed plugin version.
 * @return bool
 */
function xmldb_local_proctorcore_upgrade(int $oldversion): bool {
    return true;
}
