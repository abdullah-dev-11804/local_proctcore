<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Generates and stores Section 3.1 PDF reports.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_pdf_service {
    /** @var report_service */
    private $reports;

    /** @var asset_repository */
    private $assets;

    /** @var retention_policy */
    private $retention;

    /** @var asset_access_service */
    private $assetaccess;

    /** @var audit_logger */
    private $audit;

    /**
     * Constructor.
     *
     * @param report_service|null $reports Optional report service.
     * @param asset_repository|null $assets Optional asset repository.
     * @param retention_policy|null $retention Optional retention policy.
     * @param asset_access_service|null $assetaccess Optional asset reader.
     * @param audit_logger|null $audit Optional audit logger.
     */
    public function __construct(
        ?report_service $reports = null,
        ?asset_repository $assets = null,
        ?retention_policy $retention = null,
        ?asset_access_service $assetaccess = null,
        ?audit_logger $audit = null
    ) {
        $this->reports = $reports ?? new report_service();
        $this->assets = $assets ?? new asset_repository();
        $this->retention = $retention ?? new retention_policy();
        $this->assetaccess = $assetaccess ?? new asset_access_service();
        $this->audit = $audit ?? new audit_logger();
    }

    /**
     * Generates and stores a PDF, replacing the previous generated copy.
     *
     * @param int $sessionid Session id.
     * @param int|null $actoruserid Actor, or null for automation.
     * @param string $reason Generation reason.
     * @return \stdClass Report asset row.
     */
    public function generate_and_store(
        int $sessionid,
        ?int $actoruserid = null,
        string $reason = 'automatic'
    ): \stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/pdflib.php');

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_proctorcore_report');
        $lock = $lockfactory->get_lock('session-' . $sessionid, 15);
        if (!$lock) {
            throw new \moodle_exception('error:reportgenerationbusy', 'local_proctorcore');
        }

        try {
            $report = $this->reports->get_session_report_for_generation($sessionid);
            $session = $report['session'];
            $completedat = (int) ($session->endedat ?: $session->quiztimefinish ?: time());
            $expiresat = $this->retention->compute_report_expiry((int) $session->companyid, $completedat);
            if ($expiresat <= time()) {
                throw new \moodle_exception('error:reportexpired', 'local_proctorcore');
            }
            $sourceversion = (int) $report['sourceModified'];
            $currentreportexpiry = (int) ($session->reportexpiresat ?? 0);
            $session->reportexpiresat = max($currentreportexpiry, $expiresat);
            $pdfbytes = $this->build_pdf($report);
            $filename = 'proctorcore-report-' . (int) $session->id . '.pdf';
            $context = \context_system::instance();
            $fs = get_file_storage();

            $fs->delete_area_files($context->id, 'local_proctorcore', 'reports', (int) $session->id);
            $file = $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'local_proctorcore',
                'filearea' => 'reports',
                'itemid' => (int) $session->id,
                'filepath' => '/',
                'filename' => $filename,
                'userid' => $actoruserid,
            ], $pdfbytes);

            if ($currentreportexpiry < $expiresat) {
                $DB->update_record('local_proctorcore_sessions', (object) [
                    'id' => (int) $session->id,
                    'reportexpiresat' => $expiresat,
                ]);
            }

            $asset = $this->assets->upsert_generated_report(
                (int) $session->id,
                (int) $session->companyid,
                $file,
                $expiresat,
                [
                    'contextid' => $context->id,
                    'filename' => $filename,
                    'generatedAt' => time(),
                    'sourceModified' => $sourceversion,
                    'generationReason' => clean_param($reason, PARAM_ALPHANUMEXT),
                    'provisional' => (string) $session->result === 'unknown',
                ]
            );

            $this->audit->log(
                'report.generated',
                (int) $session->companyid,
                (int) $session->id,
                (int) $session->userid,
                [
                    'assetId' => (int) $asset->id,
                    'filename' => $filename,
                    'size' => $file->get_filesize(),
                    'expiresAt' => $expiresat,
                    'sourceModified' => $sourceversion,
                    'reason' => $reason,
                    'provisional' => (string) $session->result === 'unknown',
                ],
                $actoruserid,
                'asset',
                (int) $asset->id
            );

            return $asset;
        } finally {
            $lock->release();
        }
    }

    /**
     * Returns a current generated report, regenerating stale/missing output.
     *
     * @param int $sessionid Session id.
     * @param int|null $actoruserid Actor.
     * @return \stdClass Asset row.
     */
    public function get_or_generate(int $sessionid, ?int $actoruserid = null): \stdClass {
        $session = $this->reports->get_session_record($sessionid);
        if (!empty($session->reportexpiresat) && (int) $session->reportexpiresat <= time()) {
            throw new \moodle_exception('error:reportexpired', 'local_proctorcore');
        }
        $asset = $this->assets->get_generated_report($sessionid);
        $sourcemodified = $this->reports->get_source_modified($sessionid);
        if ($asset) {
            $metadata = json_decode((string) ($asset->metadata ?? ''), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $assetversion = (int) ($metadata['sourceModified'] ?? 0);
            try {
                $this->assetaccess->get_moodle_file($asset);
                if ($assetversion >= $sourcemodified) {
                    return $asset;
                }
            } catch (\Throwable $exception) {
                // Regenerate below.
            }
        }
        return $this->generate_and_store($sessionid, $actoruserid, 'on_demand');
    }

    /**
     * Builds the PDF bytes.
     *
     * @param array $report Complete report model.
     * @return string
     */
    private function build_pdf(array $report): string {
        $session = $report['session'];
        $pdf = new \pdf('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('SENTAL ProctorCore');
        $pdf->SetAuthor('SENTAL LMS');
        $pdf->SetTitle(get_string('report:pdftitle', 'local_proctorcore', (int) $session->id));
        $pdf->SetSubject(get_string('report:title', 'local_proctorcore'));
        $pdf->SetMargins(15, 16, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->AddPage();
        $pdf->SetFont('freesans', '', 9);

        $sessionidtext = !empty($session->server_sessionid)
            ? (string) $session->server_sessionid
            : 'M-' . (int) $session->id;
        $html = '<h1 style="font-size:18px;">' . s(get_string('report:title', 'local_proctorcore')) . '</h1>';
        $html .= '<table border="1" cellpadding="5">';
        $html .= $this->pdf_row(get_string('report:sessionid', 'local_proctorcore'), $sessionidtext);
        $html .= $this->pdf_row(get_string('report:student', 'local_proctorcore'), (string) $session->studentname);
        $html .= $this->pdf_row(get_string('report:email', 'local_proctorcore'), (string) $session->email);
        $html .= $this->pdf_row(get_string('report:company', 'local_proctorcore'), (string) $session->companyname);
        $html .= $this->pdf_row(get_string('report:course', 'local_proctorcore'), (string) $session->coursename);
        $html .= $this->pdf_row(get_string('report:quiz', 'local_proctorcore'), (string) $session->quizname);
        $html .= $this->pdf_row(get_string('report:attempt', 'local_proctorcore'), (string) ($session->attemptnumber ?? '—'));
        $html .= $this->pdf_row(get_string('report:starttime', 'local_proctorcore'),
            $report['starttime'] ? userdate((int) $report['starttime']) : '—');
        $html .= $this->pdf_row(get_string('report:endtime', 'local_proctorcore'),
            $report['endtime'] ? userdate((int) $report['endtime']) : get_string('report:pending', 'local_proctorcore'));
        $html .= $this->pdf_row(get_string('report:duration', 'local_proctorcore'),
            $report['duration'] ? format_time((int) $report['duration']) : '—');
        $html .= $this->pdf_row(get_string('report:result', 'local_proctorcore'), ucfirst((string) $session->result));
        $html .= $this->pdf_row(get_string('report:status', 'local_proctorcore'), ucfirst((string) $session->status));
        $html .= $this->pdf_row(get_string('report:identitystatus', 'local_proctorcore'), ucfirst((string) $session->identitystatus));
        $html .= $this->pdf_row(get_string('report:technicalstatus', 'local_proctorcore'), ucfirst((string) $session->techcheckstatus));
        $html .= $this->pdf_row(get_string('report:grade', 'local_proctorcore'),
            $report['grade'] !== null ? format_float((float) $report['grade'], 2) : '—');
        $html .= $this->pdf_row(get_string('report:percentage', 'local_proctorcore'),
            $report['percent'] !== null ? format_float((float) $report['percent'], 2) . '%' : '—');
        $html .= '</table><br>';

        if ($report['participantfields']) {
            $html .= '<h2 style="font-size:13px;">' . s(get_string('report:participantfields', 'local_proctorcore')) . '</h2>';
            $html .= '<table border="1" cellpadding="4">';
            foreach ($report['participantfields'] as $field) {
                $html .= $this->pdf_row((string) $field->name, (string) ($field->value ?? ''));
            }
            $html .= '</table><br>';
        }

        $html .= '<h2 style="font-size:13px;">' . s(get_string('report:violations', 'local_proctorcore')) . '</h2>';
        if (!$report['violations']) {
            $html .= '<p>' . s(get_string('report:noviolations', 'local_proctorcore')) . '</p>';
        } else {
            $html .= '<table border="1" cellpadding="4">'
                . '<tr style="font-weight:bold;background-color:#eeeeee;">'
                . '<td width="24%">' . s(get_string('report:time', 'local_proctorcore')) . '</td>'
                . '<td width="22%">' . s(get_string('report:type', 'local_proctorcore')) . '</td>'
                . '<td width="12%">' . s(get_string('report:severity', 'local_proctorcore')) . '</td>'
                . '<td width="42%">' . s(get_string('report:description', 'local_proctorcore')) . '</td></tr>';
            foreach ($report['violations'] as $violation) {
                $html .= '<tr><td>' . s(userdate((int) $violation->occurredat)) . '</td>'
                    . '<td>' . s(ucfirst(str_replace('_', ' ', (string) $violation->type))) . '</td>'
                    . '<td>' . (int) $violation->severity . '</td>'
                    . '<td>' . s((string) ($violation->description ?? '')) . '</td></tr>';
            }
            $html .= '</table>';
        }
        $pdf->writeHTML($html, true, false, true, false, '');

        $snapshotdefinitions = [
            'identity' => get_string('report:identitysnapshots', 'local_proctorcore'),
            'violations' => get_string('report:violationsnapshots', 'local_proctorcore'),
            'submission' => get_string('report:submissionsnapshots', 'local_proctorcore'),
            'snapshots' => get_string('report:othersnapshots', 'local_proctorcore'),
        ];
        foreach ($snapshotdefinitions as $key => $heading) {
            foreach ($report['assets'][$key] as $asset) {
                $content = $this->assetaccess->get_image_content($asset);
                if ($content === null) {
                    continue;
                }
                $pdf->AddPage();
                $pdf->SetFont('freesans', 'B', 13);
                $pdf->Cell(0, 8, $heading, 0, 1);
                $pdf->SetFont('freesans', '', 9);
                $pdf->Cell(0, 6, userdate((int) $asset->timecreated), 0, 1);
                try {
                    $pdf->Image('@' . $content, 15, 34, 180, 0);
                } catch (\Throwable $exception) {
                    $pdf->writeHTML('<p>' . s(get_string('report:snapshotunavailable', 'local_proctorcore')) . '</p>');
                }
            }
        }

        $pdf->AddPage();
        $pdf->SetFont('freesans', 'B', 13);
        $pdf->Cell(0, 8, get_string('report:retention', 'local_proctorcore'), 0, 1);
        $pdf->SetFont('freesans', '', 9);
        $retentionhtml = '<table border="1" cellpadding="5">'
            . $this->pdf_row(get_string('report:appealuntil', 'local_proctorcore'),
                !empty($session->appealuntil) ? userdate((int) $session->appealuntil) : '—')
            . $this->pdf_row(get_string('report:reportexpiresat', 'local_proctorcore'),
                !empty($session->reportexpiresat) ? userdate((int) $session->reportexpiresat) : '—')
            . $this->pdf_row(get_string('report:videoexpiresat', 'local_proctorcore'),
                !empty($session->videoexpiresat) ? userdate((int) $session->videoexpiresat) : '—')
            . '</table>';
        $pdf->writeHTML($retentionhtml, true, false, true, false, '');

        return $pdf->Output('', 'S');
    }

    /** @param string $label @param string $value @return string */
    private function pdf_row(string $label, string $value): string {
        return '<tr><td width="35%" style="font-weight:bold;background-color:#f3f3f3;">'
            . s($label) . '</td><td width="65%">' . s($value) . '</td></tr>';
    }
}
