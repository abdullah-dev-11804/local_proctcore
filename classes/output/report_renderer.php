<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\output;

defined('MOODLE_INTERNAL') || die();

/**
<<<<<<< HEAD
 * Prepares proctoring report pages, links, violations, and evidence summaries.
 */
final class report_renderer {
=======
 * Converts report service data into Mustache-safe values.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_renderer {
    /**
     * Prepares a report detail page.
     *
     * @param array $report Raw report model.
     * @param int $viewerid Current viewer id.
     * @param bool $candownload Whether PDF download is allowed.
     * @return array
     */
    public static function prepare_detail(array $report, int $viewerid, bool $candownload): array {
        $session = $report['session'];
        $result = self::status((string) $session->result);
        $status = self::status((string) $session->status);
        $identity = self::status((string) $session->identitystatus);
        $technical = self::status((string) $session->techcheckstatus);

        $violations = [];
        foreach ($report['violations'] as $violation) {
            $violations[] = [
                'id' => (int) $violation->id,
                'time' => userdate((int) $violation->occurredat),
                'type' => self::humanise((string) $violation->type),
                'severity' => (int) $violation->severity,
                'status' => self::humanise((string) $violation->status),
                'description' => format_text((string) ($violation->description ?? ''), FORMAT_PLAIN),
                'duration' => !empty($violation->durationms)
                    ? format_float(((int) $violation->durationms) / 1000, 1) . ' s'
                    : get_string('report:notavailable', 'local_proctorcore'),
            ];
        }

        $snapshotgroups = [];
        $snapshotdefinitions = [
            'identity' => get_string('report:identitysnapshots', 'local_proctorcore'),
            'violations' => get_string('report:violationsnapshots', 'local_proctorcore'),
            'submission' => get_string('report:submissionsnapshots', 'local_proctorcore'),
            'snapshots' => get_string('report:othersnapshots', 'local_proctorcore'),
        ];
        foreach ($snapshotdefinitions as $key => $label) {
            $items = [];
            foreach ($report['assets'][$key] as $asset) {
                $items[] = self::prepare_asset($asset, true);
            }
            if ($items) {
                $snapshotgroups[] = ['label' => $label, 'items' => $items];
            }
        }

        $videos = [];
        foreach ($report['assets']['videos'] as $asset) {
            $videos[] = self::prepare_asset($asset, false);
        }

        $fields = [];
        foreach ($report['participantfields'] as $field) {
            $fields[] = [
                'name' => format_string((string) $field->name),
                'value' => format_text((string) ($field->value ?? ''), FORMAT_PLAIN),
            ];
        }

        $check = $report['check'];
        $checkitems = [];
        if ($check) {
            $checkitems = [
                ['name' => get_string('report:internetspeed', 'local_proctorcore'),
                    'value' => $check->speed_mbps !== null ? format_float((float) $check->speed_mbps, 2) . ' Mbps' : '—'],
                ['name' => get_string('report:camera', 'local_proctorcore'),
                    'value' => self::yesno((bool) $check->cameraok)],
                ['name' => get_string('report:microphone', 'local_proctorcore'),
                    'value' => self::yesno((bool) $check->microphoneok)],
                ['name' => get_string('report:lighting', 'local_proctorcore'),
                    'value' => self::yesno((bool) $check->lightingok)],
            ];
        }

        $start = (int) $report['starttime'];
        $end = (int) $report['endtime'];
        $sessionidtext = !empty($session->server_sessionid)
            ? (string) $session->server_sessionid
            : 'M-' . (int) $session->id;

        return [
            'title' => get_string('report:title', 'local_proctorcore'),
            'sessionid' => $sessionidtext,
            'localsessionid' => (int) $session->id,
            'studentname' => format_string((string) $session->studentname),
            'studentemail' => s((string) $session->email),
            'studentidnumber' => s((string) ($session->studentidnumber ?? '')),
            'companyname' => format_string((string) $session->companyname),
            'coursename' => format_string((string) $session->coursename),
            'quizname' => format_string((string) $session->quizname),
            'attemptnumber' => (int) ($session->attemptnumber ?? 0),
            'attemptstate' => self::humanise((string) ($session->attemptstate ?? 'unknown')),
            'starttime' => $start > 0 ? userdate($start) : '—',
            'endtime' => $end > 0 ? userdate($end) : get_string('report:pending', 'local_proctorcore'),
            'duration' => (int) $report['duration'] > 0 ? format_time((int) $report['duration']) : '—',
            'grade' => $report['grade'] !== null ? format_float((float) $report['grade'], 2) : '—',
            'percent' => $report['percent'] !== null ? format_float((float) $report['percent'], 2) . '%' : '—',
            'resulttext' => $result['text'],
            'resultclass' => $result['class'],
            'statustext' => $status['text'],
            'statusclass' => $status['class'],
            'identitytext' => $identity['text'],
            'identityclass' => $identity['class'],
            'technicaltext' => $technical['text'],
            'technicalclass' => $technical['class'],
            'risk' => $session->risk_score !== null ? format_float((float) $session->risk_score, 2) : '—',
            'violationcount' => count($violations),
            'snapshotcount' => (int) $session->snapshotcount,
            'violations' => $violations,
            'hasviolations' => !empty($violations),
            'snapshotgroups' => $snapshotgroups,
            'hassnapshots' => !empty($snapshotgroups),
            'videos' => $videos,
            'hasvideos' => !empty($videos),
            'fields' => $fields,
            'hasfields' => !empty($fields),
            'checkitems' => $checkitems,
            'hascheck' => !empty($checkitems),
            'appealuntil' => !empty($session->appealuntil) ? userdate((int) $session->appealuntil) : '—',
            'reportexpiresat' => !empty($session->reportexpiresat) ? userdate((int) $session->reportexpiresat) : '—',
            'videoexpiresat' => !empty($session->videoexpiresat) ? userdate((int) $session->videoexpiresat) : '—',
            'candownload' => $candownload,
            'downloadurl' => (new \moodle_url('/local/proctorcore/download_report.php', [
                'sessionid' => (int) $session->id,
            ]))->out(false),
            'backurl' => (new \moodle_url('/local/proctorcore/reports.php'))->out(false),
            'attempturl' => (new \moodle_url('/mod/quiz/review.php', [
                'attempt' => (int) $session->attemptid,
            ]))->out(false),
            'viewerid' => $viewerid,
        ];
    }

    /**
     * Prepares a report list.
     *
     * @param array $records Joined list rows.
     * @return array
     */
    public static function prepare_list(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $result = self::status((string) $record->result);
            $rows[] = [
                'sessionid' => !empty($record->server_sessionid)
                    ? s((string) $record->server_sessionid)
                    : 'M-' . (int) $record->id,
                'studentname' => format_string((string) $record->studentname),
                'companyname' => format_string((string) $record->companyname),
                'coursename' => format_string((string) $record->coursename),
                'quizname' => format_string((string) $record->quizname),
                'attemptnumber' => (int) ($record->attemptnumber ?? 0),
                'endedat' => !empty($record->endedat)
                    ? userdate((int) $record->endedat)
                    : get_string('report:pending', 'local_proctorcore'),
                'resulttext' => $result['text'],
                'resultclass' => $result['class'],
                'violationcount' => (int) $record->violationcount,
                'detailurl' => (new \moodle_url('/local/proctorcore/reports.php', [
                    'sessionid' => (int) $record->id,
                ]))->out(false),
                'candownload' => !empty($record->candownload),
                'downloadurl' => (new \moodle_url('/local/proctorcore/download_report.php', [
                    'sessionid' => (int) $record->id,
                ]))->out(false),
            ];
        }
        return [
            'rows' => $rows,
            'hasrows' => !empty($rows),
        ];
    }

    /**
     * Prepares one evidence asset.
     *
     * @param \stdClass $asset Asset row.
     * @param bool $isimage Whether to render an image preview.
     * @return array
     */
    private static function prepare_asset(\stdClass $asset, bool $isimage): array {
        $url = new \moodle_url('/local/proctorcore/evidence.php', ['assetid' => (int) $asset->id]);
        return [
            'id' => (int) $asset->id,
            'filename' => s((string) $asset->filename),
            'type' => self::humanise((string) $asset->assettype),
            'reason' => self::humanise((string) ($asset->reason ?: $asset->assettype)),
            'createdat' => userdate((int) $asset->timecreated),
            'filesize' => $asset->filesize !== null ? display_size((int) $asset->filesize) : '—',
            'viewurl' => $url->out(false),
            'isimage' => $isimage,
        ];
    }

    /**
     * Returns display text and Bootstrap-compatible class.
     *
     * @param string $value Status/result value.
     * @return array
     */
    private static function status(string $value): array {
        $normal = strtolower(trim($value));
        $class = 'badge-secondary';
        if (in_array($normal, ['passed', 'completed', 'active'], true)) {
            $class = 'badge-success';
        } else if (in_array($normal, ['failed', 'abandoned', 'expired'], true)) {
            $class = 'badge-danger';
        } else if (in_array($normal, ['pending', 'unknown', 'created', 'precheck', 'interrupted'], true)) {
            $class = 'badge-warning';
        }
        return ['text' => self::humanise($normal), 'class' => $class];
    }

    /** @param bool $value @return string */
    private static function yesno(bool $value): string {
        return $value ? get_string('yes') : get_string('no');
    }

    /** @param string $value @return string */
    private static function humanise(string $value): string {
        $value = trim(str_replace(['_', '-'], ' ', $value));
        return $value === '' ? '—' : ucfirst($value);
    }
>>>>>>> origin/danial
}
