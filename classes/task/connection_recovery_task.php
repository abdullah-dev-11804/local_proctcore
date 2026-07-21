<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Detects lost heartbeats and closes expired reconnect windows.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connection_recovery_task extends \core\task\scheduled_task {
    /** @return string */
    public function get_name(): string {
        return get_string('task:connection_recovery', 'local_proctorcore');
    }

    /** @return void */
    public function execute(): void {
        $service = new \local_proctorcore\local\connection_recovery_service();
        $result = $service->process_timeouts(200);

        mtrace('ProctorCore connection recovery: checked=' . (int) $result['checked']
            . ', interrupted=' . (int) $result['interrupted']
            . ', expired=' . (int) $result['expired']
            . ', errors=' . (int) $result['errors']);
    }
}
