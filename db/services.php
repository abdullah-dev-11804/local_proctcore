<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_proctorcore_receive_webhook' => [
        'classname' => 'local_proctorcore\external\webhook_receiver',
        'methodname' => 'receive',
        'classpath' => '',
        'description' => 'Receives signed session lifecycle and result events from Server B.',
        'type' => 'write',
        'ajax' => false,
        'loginrequired' => false,
    ],
];

$services = [
    'ProctorCore Server B integration' => [
        'functions' => [
            'local_proctorcore_receive_webhook',
        ],
        'restrictedusers' => 1,
        'enabled' => 0,
    ],
];
