<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
<<<<<<< HEAD
 * Handles schema/data upgrades for ProctorCore.
=======
 * Plugin upgrade steps.
 *
 * All upgrades below are code/settings/task registrations only. The existing
 * install.xml schema is intentionally preserved and no database table or field
 * is changed by these steps.
>>>>>>> origin/danial
 *
 * @param int $oldversion Installed plugin version.
 * @return bool
 */
function xmldb_local_proctorcore_upgrade(int $oldversion): bool {
<<<<<<< HEAD
=======
    if ($oldversion < 2026071501) {
        upgrade_plugin_savepoint(true, 2026071501, 'local', 'proctorcore');
    }

    if ($oldversion < 2026071502) {
        // Section 4.2 signed Passed/Failed webhook; no schema change.
        upgrade_plugin_savepoint(true, 2026071502, 'local', 'proctorcore');
    }

    if ($oldversion < 2026071601) {
        // Section 5.3 heartbeat, reconnect endpoints and scheduled task; no schema change.
        upgrade_plugin_savepoint(true, 2026071601, 'local', 'proctorcore');
    }

    if ($oldversion < 2026071603) {
        // Consolidated compatibility release for quizaccess_proctorcore; no schema change.
        upgrade_plugin_savepoint(true, 2026071603, 'local', 'proctorcore');
    }

    if ($oldversion < 2026071700) {
        // Section 5.1 browser/device preflight and administrator preview; no schema change.
        upgrade_plugin_savepoint(true, 2026071700, 'local', 'proctorcore');
    }

    if ($oldversion < 2026071705) {
        // Classic compact preflight design matching the original quizaccess widget; no schema change.
        upgrade_plugin_savepoint(true, 2026071705, 'local', 'proctorcore');
    }

    if ($oldversion < 2026071707) {
        // Section 1.1 media capture, asset webhook, retention cleanup, and configurable
        // Section 5.1 internet/lighting thresholds; no schema change.
        upgrade_plugin_savepoint(true, 2026071707, 'local', 'proctorcore');
    }

    if ($oldversion < 2026072000) {
        // Section 3.1 automatic HTML/PDF reports, protected evidence links,
        // navigation, auditing, and scheduled report generation; no schema change.
        upgrade_plugin_savepoint(true, 2026072000, 'local', 'proctorcore');
    }

    if ($oldversion < 2026072001) {
        // Quiz-specific report navigation, teacher-authorised report access,
        // and PDF actions in the report list; no schema change.
        upgrade_plugin_savepoint(true, 2026072001, 'local', 'proctorcore');
    }

    if ($oldversion < 2026072003) {
        // Sections 1.2 and 1.3: ML-backed identity verification, active
        // liveness, behaviour monitoring, and violation evidence; no schema change.
        upgrade_plugin_savepoint(true, 2026072003, 'local', 'proctorcore');
    }

>>>>>>> origin/danial
    return true;
}
