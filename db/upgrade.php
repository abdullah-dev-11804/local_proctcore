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

    if ($oldversion < 2026090900) {
        $addfield = static function(xmldb_table $table, xmldb_field $field) use ($dbman): void {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        };

        $companycfg = new xmldb_table('local_proctorcore_companycfg');
        $mismatchfield = new xmldb_field(
            'identitymismatchmode', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, 'review', 'appealperioddays'
        );
        if ($dbman->field_exists($companycfg, $mismatchfield)) {
            $dbman->change_field_default($companycfg, $mismatchfield);
        }
        $addfield($companycfg, new xmldb_field(
            'identitythreshold', XMLDB_TYPE_NUMBER, '10, 4', null, null, null, null, 'identitymismatchmode'
        ));

        $faceenrol = new xmldb_table('local_proctorcore_faceenrol');
        $addfield($faceenrol, new xmldb_field(
            'deletionstatus', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'none', 'servermetadata'
        ));
        $addfield($faceenrol, new xmldb_field(
            'deletionattempts', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'deletionstatus'
        ));
        $addfield($faceenrol, new xmldb_field(
            'deletionrequestedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'deletionattempts'
        ));
        $addfield($faceenrol, new xmldb_field(
            'deletedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'deletionrequestedat'
        ));
        $addfield($faceenrol, new xmldb_field(
            'deletionerror', XMLDB_TYPE_TEXT, null, null, null, null, null, 'deletedat'
        ));

        $quizcfg = new xmldb_table('local_proctorcore_quizcfg');
        $addfield($quizcfg, new xmldb_field(
            'identitymismatchmode', XMLDB_TYPE_CHAR, '16', null, null, null, null, 'settingsjson'
        ));
        $addfield($quizcfg, new xmldb_field(
            'identitythreshold', XMLDB_TYPE_NUMBER, '10, 4', null, null, null, null, 'identitymismatchmode'
        ));

        $sessions = new xmldb_table('local_proctorcore_sessions');
        $addfield($sessions, new xmldb_field(
            'identityscore', XMLDB_TYPE_NUMBER, '10, 4', null, null, null, null, 'identitystatus'
        ));
        $addfield($sessions, new xmldb_field(
            'identitythreshold', XMLDB_TYPE_NUMBER, '10, 4', null, null, null, null, 'identityscore'
        ));
        $addfield($sessions, new xmldb_field(
            'identitypolicy', XMLDB_TYPE_CHAR, '16', null, null, null, null, 'identitythreshold'
        ));
        $addfield($sessions, new xmldb_field(
            'reviewrequired', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'identitypolicy'
        ));
        $addfield($sessions, new xmldb_field(
            'mediastatus', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'pending', 'techcheckstatus'
        ));

        upgrade_plugin_savepoint(true, 2026090900, 'local', 'proctorcore');
    }

    if ($oldversion < 2026091000) {
        // Day 1 recording, monitoring, report, retention, and tenant hardening.
        upgrade_plugin_savepoint(true, 2026091000, 'local', 'proctorcore');
    }

    if ($oldversion < 2026091001) {
        // Keep reports pending until all final-manifest evidence webhooks arrive.
        upgrade_plugin_savepoint(true, 2026091001, 'local', 'proctorcore');
    }

    if ($oldversion < 2026091100) {
        // Candidate precheck and identity interface refresh; no schema changes.
        upgrade_plugin_savepoint(true, 2026091100, 'local', 'proctorcore');
    }

    if ($oldversion < 2026091101) {
        // Keep the access-rule deployment compatible during rolling upgrades.
        upgrade_plugin_savepoint(true, 2026091101, 'local', 'proctorcore');
    }

    if ($oldversion < 2026091102) {
        // Fit the candidate workflow inside Moodle's quiz preflight dialogue.
        upgrade_plugin_savepoint(true, 2026091102, 'local', 'proctorcore');
    }

    if ($oldversion < 2026091103) {
        // Add precise candidate guidance for rejected identity captures.
        upgrade_plugin_savepoint(true, 2026091103, 'local', 'proctorcore');
    }

    if ($oldversion < 2026091200) {
        $fields = new xmldb_table('local_proctorcore_fields');
        $fielddefinitions = [
            new xmldb_field('namekk', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'name'),
            new xmldb_field('nameru', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'namekk'),
            new xmldb_field('helptext', XMLDB_TYPE_TEXT, null, null, null, null, null, 'nameru'),
            new xmldb_field('helpkk', XMLDB_TYPE_TEXT, null, null, null, null, null, 'helptext'),
            new xmldb_field('helpru', XMLDB_TYPE_TEXT, null, null, null, null, null, 'helpkk'),
            new xmldb_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'editablebyuser'),
            new xmldb_field('profilefieldid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'active'),
        ];
        foreach ($fielddefinitions as $field) {
            if (!$dbman->field_exists($fields, $field)) {
                $dbman->add_field($fields, $field);
            }
        }

        $uservals = new xmldb_table('local_proctorcore_uservals');
        if (!$dbman->table_exists($uservals)) {
            $uservals->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $uservals->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $uservals->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $uservals->add_field('fieldid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $uservals->add_field('value', XMLDB_TYPE_TEXT);
            $uservals->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $uservals->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $uservals->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $uservals->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $uservals->add_key('fieldid', XMLDB_KEY_FOREIGN, ['fieldid'], 'local_proctorcore_fields', ['id']);
            $uservals->add_index('userfield', XMLDB_INDEX_UNIQUE, ['companyid', 'userid', 'fieldid']);
            $dbman->create_table($uservals);
        }

        $documents = new xmldb_table('local_proctorcore_consentdoc');
        if (!$dbman->table_exists($documents)) {
            $documents->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $documents->add_field('shortname', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $documents->add_field('active', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $documents->add_field('required', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $documents->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $documents->add_field('currentversion', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $documents->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $documents->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $documents->add_field('usermodified', XMLDB_TYPE_INTEGER, '10');
            $documents->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $documents->add_key('usermodified', XMLDB_KEY_FOREIGN, ['usermodified'], 'user', ['id']);
            $documents->add_index('shortname', XMLDB_INDEX_UNIQUE, ['shortname']);
            $documents->add_index('activesort', XMLDB_INDEX_NOTUNIQUE, ['active', 'sortorder']);
            $dbman->create_table($documents);
        }

        $versions = new xmldb_table('local_proctorcore_consentver');
        if (!$dbman->table_exists($versions)) {
            $versions->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $versions->add_field('documentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $versions->add_field('version', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            foreach (['titlekk', 'titleru', 'titleen'] as $name) {
                $versions->add_field($name, XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
            }
            foreach (['contentkk', 'contentru', 'contenten'] as $name) {
                $versions->add_field($name, XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            }
            $versions->add_field('materialchange', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
            $versions->add_field('contenthash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $versions->add_field('publishedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $versions->add_field('publishedby', XMLDB_TYPE_INTEGER, '10');
            $versions->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $versions->add_key('documentid', XMLDB_KEY_FOREIGN, ['documentid'], 'local_proctorcore_consentdoc', ['id']);
            $versions->add_key('publishedby', XMLDB_KEY_FOREIGN, ['publishedby'], 'user', ['id']);
            $versions->add_index('documentversion', XMLDB_INDEX_UNIQUE, ['documentid', 'version']);
            $dbman->create_table($versions);
        }

        $logs = new xmldb_table('local_proctorcore_consentlog');
        if (!$dbman->table_exists($logs)) {
            $logs->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $logs->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $logs->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $logs->add_field('language', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL);
            $logs->add_field('documentversions', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $logs->add_field('documentshash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
            $logs->add_field('ipaddress', XMLDB_TYPE_CHAR, '45');
            $logs->add_field('useragent', XMLDB_TYPE_TEXT);
            $logs->add_field('consentedat', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $logs->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $logs->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $logs->add_index('userhash', XMLDB_INDEX_NOTUNIQUE, ['userid', 'documentshash']);
            $logs->add_index('companytime', XMLDB_INDEX_NOTUNIQUE, ['companyid', 'consentedat']);
            $dbman->create_table($logs);
        }

        upgrade_plugin_savepoint(true, 2026091200, 'local', 'proctorcore');
    }

    return true;
}
