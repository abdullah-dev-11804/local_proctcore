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
    // Delegate to the business logic layer to check identity and tech readiness.
    return \local_proctorcore\local\gate_service::is_attempt_allowed($attemptid);
}

/**
 * Returns the current user's IOMAD company id when IOMAD is available.
 *
 * @param int $userid User id.
 * @return int Company id, or 0 for site/global context.
 */
function local_proctorcore_get_user_companyid(int $userid): int {
    // Delegate to the tenant resolver to handle IOMAD checks.
    return \local_proctorcore\local\tenant_resolver::get_user_companyid($userid);
}