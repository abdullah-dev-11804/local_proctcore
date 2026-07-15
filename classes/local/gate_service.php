<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Answers quizaccess_proctorgate admission checks for proctored attempts.
 */
final class gate_service {

    /**
     * Determines if a specific quiz attempt is allowed to proceed based on proctoring status.
     *
     * @param int $attemptid The quiz attempt ID.
     * @return bool True if allowed, false if blocked by proctoring requirements.
     */
    public static function is_attempt_allowed(int $attemptid): bool {
        // Fetch the official session record we built in Step 2
        $session = session_repository::get_by_attempt($attemptid);

        // If there is no proctoring session linked, this isn't a proctored quiz. Let them in.
        if (!$session) {
            return true; 
        }

        // If the session was explicitly failed or ended, lock them out.
        if (in_array($session->status, ['failed', 'terminated', 'closed'])) {
            return false;
        }

        // The student must have cleared both the identity check and the tech check.
        // (Status values assume 'verified' and 'passed' as success states from Server B).
        $identity_cleared = ($session->identitystatus === 'verified');
        $tech_cleared = ($session->techcheckstatus === 'passed');

        return $identity_cleared && $tech_cleared;
    }
}