<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;

/**
 * Describes personal data stored by the proctoring record keeper.
 */
final class provider implements \core_privacy\local\metadata\provider {
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_proctorcore_sessions', [
            'userid' => 'privacy:metadata:sessions:userid',
            'result' => 'privacy:metadata:sessions:result',
            'servermetadata' => 'privacy:metadata:sessions:servermetadata',
        ], 'privacy:metadata:sessions');

        $collection->add_database_table('local_proctorcore_violations', [
            'userid' => 'privacy:metadata:violations:userid',
            'type' => 'privacy:metadata:violations:type',
            'description' => 'privacy:metadata:violations:description',
        ], 'privacy:metadata:violations');

        $collection->add_database_table('local_proctorcore_appeals', [
            'userid' => 'privacy:metadata:appeals:userid',
            'reason' => 'privacy:metadata:appeals:reason',
            'details' => 'privacy:metadata:appeals:details',
        ], 'privacy:metadata:appeals');

        return $collection;
    }
}
