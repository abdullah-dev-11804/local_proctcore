<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for official session record persistence.
 */
final class session_repository_test extends \advanced_testcase {
    
    public function test_create_get_and_update_session(): void {
        // This tells Moodle to wipe the database clean after this test finishes
        $this->resetAfterTest(true);

        // 1. Create a dummy session
        $companyid = 1;
        $courseid = 10;
        $cmid = 15;
        $quizid = 20;
        $attemptid = 100;
        $userid = 5;

        $sessionid = \local_proctorcore\local\session_repository::create_session(
            $companyid, $courseid, $cmid, $quizid, $attemptid, $userid
        );

        // Assert the record was created and gave us a valid database ID back
        $this->assertIsInt($sessionid);
        $this->assertGreaterThan(0, $sessionid);

        // 2. Retrieve it by the attempt ID
        $session = \local_proctorcore\local\session_repository::get_by_attempt($attemptid);
        
        $this->assertNotFalse($session);
        $this->assertEquals($companyid, $session->companyid);
        $this->assertEquals('created', $session->status);

        // 3. Update the session (simulating a status change)
        $session->status = 'in_progress';
        \local_proctorcore\local\session_repository::update_session($session);

        // Fetch it again to prove the update saved
        $updatedsession = \local_proctorcore\local\session_repository::get_by_attempt($attemptid);
        $this->assertEquals('in_progress', $updatedsession->status);
    }
}