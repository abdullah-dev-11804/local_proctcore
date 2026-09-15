<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/** Tests appeal queue visibility for course authorities. */
final class appeal_visibility_test extends \advanced_testcase {
    public function test_quiz_report_reviewer_can_access_course_appeal_queue(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $service = new \local_proctorcore\local\report_service();

        $this->assertTrue($service->can_review_course_appeals((int) $course->id, (int) $teacher->id));
        $this->assertFalse($service->can_review_course_appeals((int) $course->id, (int) $student->id));
    }
}
