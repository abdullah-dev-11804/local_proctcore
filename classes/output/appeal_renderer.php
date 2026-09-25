<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\output;

defined('MOODLE_INTERNAL') || die();

use local_proctorcore\local\localised_value;

/** Prepares appeal queue data for Mustache. */
final class appeal_renderer {
    /** @param \stdClass[] $records @return array */
    public static function prepare_list(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $status = strtolower((string) $record->appealstatus);
            $statusclass = in_array($status, ['approved'], true)
                ? 'badge-success'
                : (in_array($status, ['rejected'], true) ? 'badge-danger' : 'badge-warning');
            $rows[] = [
                'appealid' => (int) $record->appealid,
                'studentname' => format_string((string) $record->studentname),
                'email' => s((string) $record->email),
                'company' => get_string('report:companynumber', 'local_proctorcore', (int) $record->companyid),
                'course' => format_string((string) $record->coursename),
                'quiz' => format_string((string) $record->quizname),
                'attempt' => (int) ($record->attemptnumber ?? 0),
                'reason' => localised_value::appeal_reason((string) $record->reason),
                'status' => localised_value::value($status),
                'statusclass' => $statusclass,
                'submittedat' => userdate((int) $record->submittedat),
                'viewurl' => (new \moodle_url('/local/proctorcore/appeal.php', [
                    'sessionid' => (int) $record->sessionid,
                ]))->out(false),
            ];
        }
        return ['rows' => $rows, 'hasrows' => !empty($rows)];
    }
}
