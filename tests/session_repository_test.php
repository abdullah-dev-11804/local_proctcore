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

    public function test_quiz_policy_overrides_company_then_platform(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('identitymismatchmode', 'review', 'local_proctorcore');
        set_config('identitythreshold', '0.85', 'local_proctorcore');
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $now = time();

        $DB->insert_record('local_proctorcore_companycfg', (object) [
            'companyid' => 7,
            'enabled' => 1,
            'reportretentiondays' => 183,
            'videoretentiondays' => 30,
            'appealperioddays' => 14,
            'identitymismatchmode' => 'fail',
            'identitythreshold' => 0.90,
            'allowedlanguages' => 'en,ru,kk',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_proctorcore_quizcfg', (object) [
            'companyid' => 7,
            'courseid' => $course->id,
            'cmid' => $quiz->cmid,
            'quizid' => $quiz->id,
            'enabled' => 1,
            'identitymismatchmode' => 'block',
            'identitythreshold' => 0.95,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $config = (new \local_proctorcore\local\company_config_repository())
            ->get_effective_config(7, (int) $quiz->id);

        $this->assertSame('block', $config->identitymismatchmode);
        $this->assertEquals(0.95, $config->identitythreshold);
    }
}
