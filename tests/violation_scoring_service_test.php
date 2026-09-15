<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/** Tests administrator-configurable violation scoring decisions. */
final class violation_scoring_service_test extends \advanced_testcase {
    public function test_default_points_and_thresholds(): void {
        $this->resetAfterTest();
        $service = new \local_proctorcore\local\violation_scoring_service();

        $this->assertSame(30, $service->points_for('tab_hidden'));
        $this->assertSame('passed', $service->evaluate(39)['decision']);
        $this->assertSame('manual_review', $service->evaluate(40)['decision']);
        $this->assertSame('failed', $service->evaluate(80)['decision']);
    }

    public function test_points_are_configurable_and_failure_stays_above_review(): void {
        $this->resetAfterTest();
        set_config('riskpointstabhidden', 12, 'local_proctorcore');
        set_config('riskreviewthreshold', 90, 'local_proctorcore');
        set_config('riskfailthreshold', 20, 'local_proctorcore');
        $service = new \local_proctorcore\local\violation_scoring_service();

        $this->assertSame(12, $service->points_for('tab_hidden'));
        $this->assertSame(90, $service->get_policy()['reviewThreshold']);
        $this->assertSame(91, $service->get_policy()['failureThreshold']);
        $this->assertSame('manual_review', $service->evaluate(90)['decision']);
        $this->assertSame('failed', $service->evaluate(91)['decision']);
    }

    public function test_zero_points_disable_scoring_without_removing_violation_type(): void {
        $this->resetAfterTest();
        set_config('riskpointslookingaway', 0, 'local_proctorcore');
        $service = new \local_proctorcore\local\violation_scoring_service();

        $this->assertSame(0, $service->points_for('looking_away'));
    }
}
