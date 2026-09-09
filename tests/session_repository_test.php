<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for official session record persistence.
 */
final class session_repository_test extends \advanced_testcase {
    public function test_platform_identity_policy_defaults_to_review_and_enforces_threshold_floor(): void {
        $this->resetAfterTest();
        set_config('identitymismatchmode', 'review', 'local_proctorcore');
        set_config('identitythreshold', '0.20', 'local_proctorcore');

        $config = (new \local_proctorcore\local\company_config_repository())->get_effective_config(0);

        $this->assertSame('review', $config->identitymismatchmode);
        $this->assertEquals(0.85, $config->identitythreshold);
    }

    public function test_report_retention_has_six_month_floor(): void {
        $this->resetAfterTest();
        set_config('reportretentiondays', '20', 'local_proctorcore');

        $config = (new \local_proctorcore\local\company_config_repository())->get_effective_config(0);

        $this->assertSame(183, $config->reportretentiondays);
    }
}
