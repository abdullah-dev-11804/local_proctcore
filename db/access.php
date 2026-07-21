<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/proctorcore:viewownreport' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
        ],
    ],
    'local/proctorcore:viewcompanyreports' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/proctorcore:viewallreports' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
<<<<<<< HEAD
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
=======
        // Assign explicitly only to SENTAL global-report roles. Site administrators
        // always pass capability checks through Moodle's normal do-anything rule.
        'archetypes' => [],
>>>>>>> origin/danial
    ],
    'local/proctorcore:manage' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/proctorcore:reviewappeals' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/proctorcore:exportreports' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/proctorcore:viewaudit' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
