<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/**
 * Minimal Moodle event observers for lifecycle cleanup.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer {
    /**
     * Deletes the reusable Server B face reference when Moodle deletes a user.
     *
     * @param \core\event\user_deleted $event Moodle user-deleted event.
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        (new \local_proctorcore\local\identity_service())->erase_reference(
            (int) $event->objectid,
            'moodle_user_deleted',
            null
        );
    }
}
