<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

/**
 * Checks whether a quiz attempt has satisfied Moodle-side proctoring gates.
 *
 * @param int $attemptid Quiz attempt id.
 * @param int $userid User id.
 * @return bool
 */
function local_proctorcore_is_attempt_allowed(int $attemptid, int $userid): bool {
    // Workflow placeholder for quizaccess_proctorgate integration.
    return true;
}

/**
 * Returns the current user's IOMAD company id when IOMAD is available.
 *
 * @param int $userid User id.
 * @return int Company id, or 0 for site/global context.
 */
function local_proctorcore_get_user_companyid(int $userid): int {
    // Workflow placeholder for IOMAD tenant resolution.
    return 0;
}
