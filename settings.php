<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_proctorcore', get_string('pluginname', 'local_proctorcore'));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/serverbaseurl',
        get_string('settings:serverbaseurl', 'local_proctorcore'),
        get_string('settings:serverbaseurl_desc', 'local_proctorcore'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_proctorcore/webhooksecret',
        get_string('settings:webhooksecret', 'local_proctorcore'),
        get_string('settings:webhooksecret_desc', 'local_proctorcore'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/reportretentiondays',
        get_string('settings:reportretentiondays', 'local_proctorcore'),
        '',
        183,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/videoretentiondays',
        get_string('settings:videoretentiondays', 'local_proctorcore'),
        '',
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/appealperioddays',
        get_string('settings:appealperioddays', 'local_proctorcore'),
        '',
        14,
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}
