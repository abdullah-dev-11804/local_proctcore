<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_deleted',
        'callback' => '\local_proctorcore\observer::user_deleted',
    ],
];
