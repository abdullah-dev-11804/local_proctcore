<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle event observers for Section 1.1 lifecycle finalisation.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer {
    /**
     * Takes the submission snapshot and finalises recording for a real Quiz attempt.
     *
     * Browser JavaScript performs the same action before navigation. This observer
     * is the authoritative idempotent fallback for auto-submit, mobile navigation,
     * or a browser that closes before the keepalive requests finish.
     *
     * @param \mod_quiz\event\attempt_submitted $event Quiz event.
     * @return void
     */
    public static function quiz_attempt_submitted(\mod_quiz\event\attempt_submitted $event): void {
        try {
            $session = (new \local_proctorcore\local\session_repository())
                ->get_by_attempt_id((int) $event->objectid);
            if (!$session || !empty($event->other['preview'])) {
                return;
            }

            $capture = new \local_proctorcore\local\capture_service();
            try {
                $capture->request_snapshot((int) $session->id, null, 'submission');
            } catch (\Throwable $snapshotexception) {
                debugging(
                    'ProctorCore submission snapshot failed for session ' . (int) $session->id
                    . ': ' . $snapshotexception->getMessage(),
                    DEBUG_DEVELOPER
                );
            }

            try {
                $capture->stop_capture((int) $session->id, null, 'submitted');
            } catch (\Throwable $stopexception) {
                debugging(
                    'ProctorCore recording finalisation failed for session ' . (int) $session->id
                    . ': ' . $stopexception->getMessage(),
                    DEBUG_DEVELOPER
                );
            }

            // The Moodle Quiz is now finished even if Server B has not yet returned
            // Passed/Failed. Mark the local lifecycle ended with a provisional unknown result.
            try {
                if (!in_array((string) $session->status, ['completed', 'failed', 'abandoned', 'expired'], true)) {
                    (new \local_proctorcore\local\session_repository())->update_status(
                        (int) $session->id,
                        'completed'
                    );
                }
            } catch (\Throwable $statusException) {
                debugging(
                    'ProctorCore could not close the submitted session ' . (int) $session->id
                    . ': ' . $statusException->getMessage(),
                    DEBUG_DEVELOPER
                );
            }

            // Build a provisional report immediately at Quiz submission. The final
            // Server B Passed/Failed webhook regenerates the same PDF with the final result.
            try {
                (new \local_proctorcore\local\report_pdf_service())->generate_and_store(
                    (int) $session->id,
                    null,
                    'attempt_submitted'
                );
            } catch (\Throwable $reportexception) {
                debugging(
                    'ProctorCore report generation failed for session ' . (int) $session->id
                    . ': ' . $reportexception->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        } catch (\Throwable $exception) {
            // A proctoring integration problem must not undo an already submitted Quiz.
            debugging('ProctorCore attempt-submitted observer failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
