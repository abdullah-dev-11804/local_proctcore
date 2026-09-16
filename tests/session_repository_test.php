<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for official session record persistence.
 */
final class session_repository_test extends \advanced_testcase {
    public function test_explicit_reentry_session_becomes_latest_for_same_attempt(): void {
        $this->resetAfterTest();

        $repository = new \local_proctorcore\local\session_repository();
        $data = [
            'companyid' => 0,
            'courseid' => 101,
            'cmid' => 102,
            'quizid' => 103,
            'attemptid' => 104,
            'userid' => 105,
        ];

        $original = $repository->create_or_get($data);
        $repository->update_status((int) $original->id, 'abandoned');
        $replacement = $repository->create_new($data);

        $this->assertNotSame((int) $original->id, (int) $replacement->id);
        $this->assertSame(
            (int) $replacement->id,
            (int) $repository->get_by_attempt_id(104, 0)->id
        );
        $this->assertSame(
            (int) $replacement->id,
            (int) $repository->get_by_attempt_and_user(104, 105)->id
        );
        $this->assertSame(
            (int) $replacement->id,
            (int) $repository->create_or_get($data)->id
        );
        $this->assertSame('abandoned', $repository->get_by_id((int) $original->id)->status);
    }

    public function test_prepare_reentry_clears_old_identity_only_once(): void {
        global $SESSION;

        $this->resetAfterTest();
        $key = '7:42';
        $SESSION->local_proctorcore_identity = [
            $key => ['result' => ['passed' => true], 'rememberedAt' => time()],
        ];
        $SESSION->local_proctorcore_liveness = [
            $key => ['challengeId' => 'old'],
        ];

        $service = new \local_proctorcore\local\precheck_service();
        $service->prepare_reentry(7, 42, 99);

        $state = $SESSION->local_proctorcore_prechecks[$key];
        $this->assertSame(99, $state['reentrysessionid']);
        $this->assertNull($state['result']);
        $this->assertArrayNotHasKey($key, $SESSION->local_proctorcore_identity);
        $this->assertArrayNotHasKey($key, $SESSION->local_proctorcore_liveness);

        // A Moodle form rebuild for the same abandoned session must preserve a
        // newly obtained identity decision rather than clearing it again.
        $token = $state['token'];
        $SESSION->local_proctorcore_identity[$key] = [
            'result' => ['passed' => true],
            'rememberedAt' => time(),
        ];
        $service->prepare_reentry(7, 42, 99);
        $this->assertSame($token, $SESSION->local_proctorcore_prechecks[$key]['token']);
        $this->assertArrayHasKey($key, $SESSION->local_proctorcore_identity);
    }

    public function test_platform_identity_policy_defaults_to_review_and_enforces_threshold_floor(): void {
        $this->resetAfterTest();
        set_config('identitymismatchmode', 'review', 'local_proctorcore');
        set_config('identitythreshold', '0.20', 'local_proctorcore');

        $config = (new \local_proctorcore\local\company_config_repository())->get_effective_config(0);

        $this->assertSame('review', $config->identitymismatchmode);
        $this->assertEquals(0.85, $config->identitythreshold);
    }

    public function test_identity_movement_challenge_is_disabled_by_default(): void {
        $this->resetAfterTest();
        unset_config('identitymovementenabled', 'local_proctorcore');

        $config = (new \local_proctorcore\local\company_config_repository())->get_effective_config(0);

        $this->assertFalse($config->identitymovementenabled);
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
