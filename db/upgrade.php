<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Plugin upgrade steps.
 *
 * Upgrade steps for ProctorCore schema and data changes.
 *
 * @param int $oldversion Installed plugin version.
 * @return bool
 */
function xmldb_local_proctorcore_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

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

    if ($oldversion < 2026080700) {
        $companycfg = new xmldb_table('local_proctorcore_companycfg');
        $mismatchfield = new xmldb_field(
            'identitymismatchmode',
            XMLDB_TYPE_CHAR,
            '16',
            null,
            XMLDB_NOTNULL,
            null,
            'review',
            'appealperioddays'
        );
        if (!$dbman->field_exists($companycfg, $mismatchfield)) {
            $dbman->add_field($companycfg, $mismatchfield);
        }

        $table = new xmldb_table('local_proctorcore_faceenrol');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('status', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'active');
            $table->add_field('server_referenceid', XMLDB_TYPE_CHAR, '128', null, null, null, null);
            $table->add_field('referencekey', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('confirmedname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('confirmedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('enrolledat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('resetat', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('resetby', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('resetreason', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('qualityjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('servermetadata', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('resetby', XMLDB_KEY_FOREIGN, ['resetby'], 'user', ['id']);
            $table->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);

            $table->add_index('useriduniq', XMLDB_INDEX_UNIQUE, ['userid']);
            $table->add_index('companystatus', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'status']);
            $table->add_index('serverref', XMLDB_INDEX_NOTUNIQUE, ['server_referenceid']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026080700, 'local', 'proctorcore');
    }

    return true;
}
