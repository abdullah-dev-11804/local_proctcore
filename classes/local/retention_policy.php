<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Calculates report, video, appeal, and evidence-hold expiry dates.
 *
 * Section 1.1 (camera/microphone capture) requires three retention rules:
 * - Video clips of key moments: kept until the appeal period ends, or until
 *   the test-taker fully completes the course if an appeal was filed.
 * - PDF report with face snapshots: kept 6 months (183 days) from completion.
 * - Once all applicable periods expire, assets are deleted automatically.
 *
 * This class is the single source of truth for those calculations. Callers
 * such as {@see webhook_processor} and {@see appeal_service} must go through
 * here instead of recomputing expiry timestamps inline.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class retention_policy {
    /** Minimum PDF report retention enforced regardless of configuration. */
    private const MIN_REPORT_RETENTION_DAYS = 183;

    /** @var company_config_repository */
    private $configs;

    /**
     * Constructor.
     *
     * @param company_config_repository|null $configs Optional dependency.
     */
    public function __construct(?company_config_repository $configs = null) {
        $this->configs = $configs ?? new company_config_repository();
    }

    /**
     * Computes the PDF/report retention expiry timestamp.
     *
     * Always at least 183 days from completion, per Section 3.2 / 1.1.
     *
     * @param int $companyid IOMAD company id.
     * @param int $completedat Session completion time.
     * @return int Unix timestamp when the report may be deleted.
     */
    public function compute_report_expiry(int $companyid, int $completedat): int {
        $config = $this->configs->get_effective_config($companyid);
        $days = max(self::MIN_REPORT_RETENTION_DAYS, (int) $config->reportretentiondays);
        return $completedat + ($days * DAYSECS);
    }

    /**
     * Computes the appeal filing deadline.
     *
     * @param int $companyid IOMAD company id.
     * @param int $completedat Session completion time.
     * @return int Unix timestamp when the appeal window closes.
     */
    public function compute_appeal_deadline(int $companyid, int $completedat): int {
        $config = $this->configs->get_effective_config($companyid);
        return $completedat + ((int) $config->appealperioddays * DAYSECS);
    }

    /**
     * Computes the video/clip retention expiry timestamp.
     *
     * Video is kept at least as long as the configured video retention
     * window, and never shorter than the appeal window, since a session
     * under appeal must still have its recording available for review.
     *
     * @param int $companyid IOMAD company id.
     * @param int $completedat Session completion time.
     * @return int Unix timestamp when video may be deleted, absent a hold.
     */
    public function compute_video_expiry(int $companyid, int $completedat): int {
        $config = $this->configs->get_effective_config($companyid);
        $days = max((int) $config->videoretentiondays, (int) $config->appealperioddays);
        return $completedat + ($days * DAYSECS);
    }

    /**
     * Computes the extended video expiry once the test-taker completes the course.
     *
     * Section 1.1: "If the test-taker files an appeal, the clip is kept
     * until that test-taker fully completes the course." Course completion
     * time is not known in advance; while an appeal is open the asset is
     * held via {@see asset_repository::mark_held()} rather than an expiry
     * timestamp. Call this once the course-completion event fires to
     * finally set a real expiry and release the hold.
     *
     * @param int $coursecompletedat Timestamp the course was marked complete.
     * @return int Unix timestamp when the held video may finally be deleted.
     */
    public function extend_for_course_completion(int $coursecompletedat): int {
        // Short grace period after completion, matching report retention style.
        return $coursecompletedat + DAYSECS;
    }

    /**
     * Whether an asset has passed its expiry and carries no active hold.
     *
     * @param \stdClass $asset Row from local_proctorcore_assets.
     * @param int|null $now Optional current time override, for testing.
     * @return bool
     */
    public function is_expired(\stdClass $asset, ?int $now = null): bool {
        $now = $now ?? time();

        if (!empty($asset->isheld)) {
            return false;
        }
        if ($asset->expiresat === null) {
            return false;
        }

        return (int) $asset->expiresat <= $now;
    }
}
