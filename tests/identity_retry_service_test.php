<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/** Tests quiz-scoped identity retry counters and timed reset behavior. */
final class identity_retry_service_test extends \advanced_testcase {
    public function test_failures_lock_only_the_current_quiz_and_success_can_clear_them(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $firstquiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $secondquiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $service = new \local_proctorcore\local\identity_retry_service();

        $this->assertSame(2, $service->record_failure(7, $user->id, $firstquiz->id, 3, 900)['attemptsRemaining']);
        $this->assertSame(1, $service->record_failure(7, $user->id, $firstquiz->id, 3, 900)['attemptsRemaining']);
        $locked = $service->record_failure(7, $user->id, $firstquiz->id, 3, 900);

        $this->assertTrue($locked['locked']);
        $this->assertSame(0, $locked['attemptsRemaining']);
        $this->assertFalse($service->get_status(7, $user->id, $secondquiz->id, 3, 900)['locked']);

        $service->clear(7, $user->id, $firstquiz->id);
        $this->assertSame(3, $service->get_status(7, $user->id, $firstquiz->id, 3, 900)['attemptsRemaining']);
    }

    public function test_expired_window_resets_the_counter(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $service = new \local_proctorcore\local\identity_retry_service();

        $service->record_failure(0, $user->id, $quiz->id, 1, 60);
        $DB->set_field('local_proctorcore_idretry', 'resetat', time() - 1, [
            'companyid' => 0,
            'userid' => $user->id,
            'quizid' => $quiz->id,
        ]);

        $status = $service->get_status(0, $user->id, $quiz->id, 1, 60);
        $this->assertFalse($status['locked']);
        $this->assertSame(1, $status['attemptsRemaining']);
    }
}
