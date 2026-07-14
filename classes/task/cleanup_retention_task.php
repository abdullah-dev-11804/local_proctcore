<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Clears expired proctoring evidence references after retention periods end.
 */
final class cleanup_retention_task extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task:cleanup_retention', 'local_proctorcore');
    }

    public function execute(): void {
        // Workflow placeholder for retention cleanup and Server B deletion calls.
    }
}
