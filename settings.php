<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_proctorcore', get_string('pluginname', 'local_proctorcore'));

    $settings->add(new admin_setting_heading(
        'local_proctorcore/integrationheading',
        get_string('settings:integrationheading', 'local_proctorcore'),
        get_string('settings:integrationheading_desc', 'local_proctorcore')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_proctorcore/enabled',
        get_string('settings:enabled', 'local_proctorcore'),
        get_string('settings:enabled_desc', 'local_proctorcore'),
        0
    ));

    $settings->add(new admin_setting_configselect(
        'local_proctorcore/capturemode',
        get_string('settings:capturemode', 'local_proctorcore'),
        get_string('settings:capturemode_desc', 'local_proctorcore'),
        'serverb',
        [
            'serverb' => get_string('settings:capturemode_serverb', 'local_proctorcore'),
            'localtest' => get_string('settings:capturemode_localtest', 'local_proctorcore'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/localstoragepath',
        get_string('settings:localstoragepath', 'local_proctorcore'),
        get_string('settings:localstoragepath_desc', 'local_proctorcore'),
        $CFG->dataroot . '/proctorcore_test',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/serverbaseurl',
        get_string('settings:serverbaseurl', 'local_proctorcore'),
        get_string('settings:serverbaseurl_desc', 'local_proctorcore'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_proctorcore/serverapikey',
        get_string('settings:serverapikey', 'local_proctorcore'),
        get_string('settings:serverapikey_desc', 'local_proctorcore'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_proctorcore/webhooksecret',
        get_string('settings:webhooksecret', 'local_proctorcore'),
        get_string('settings:webhooksecret_desc', 'local_proctorcore'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/connecttimeout',
        get_string('settings:connecttimeout', 'local_proctorcore'),
        get_string('settings:connecttimeout_desc', 'local_proctorcore'),
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/requesttimeout',
        get_string('settings:requesttimeout', 'local_proctorcore'),
        get_string('settings:requesttimeout_desc', 'local_proctorcore'),
        20,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_proctorcore/verifyssl',
        get_string('settings:verifyssl', 'local_proctorcore'),
        get_string('settings:verifyssl_desc', 'local_proctorcore'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/livekitclienturl',
        get_string('settings:livekitclienturl', 'local_proctorcore'),
        get_string('settings:livekitclienturl_desc', 'local_proctorcore'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_heading(
        'local_proctorcore/precheckheading',
        get_string('settings:precheckheading', 'local_proctorcore'),
        get_string('settings:precheckheading_desc', 'local_proctorcore')
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/minimumspeedmbps',
        get_string('settings:minimumspeedmbps', 'local_proctorcore'),
        get_string('settings:minimumspeedmbps_desc', 'local_proctorcore'),
        '5.0',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/minimumlighting',
        get_string('settings:minimumlighting', 'local_proctorcore'),
        get_string('settings:minimumlighting_desc', 'local_proctorcore'),
        35,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'local_proctorcore/mlheading',
        get_string('settings:mlheading', 'local_proctorcore'),
        get_string('settings:mlheading_desc', 'local_proctorcore')
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/mlserviceurl',
        get_string('settings:mlserviceurl', 'local_proctorcore'),
        get_string('settings:mlserviceurl_desc', 'local_proctorcore'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_proctorcore/mlapikey',
        get_string('settings:mlapikey', 'local_proctorcore'),
        get_string('settings:mlapikey_desc', 'local_proctorcore'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_proctorcore/mlverifyssl',
        get_string('settings:mlverifyssl', 'local_proctorcore'),
        get_string('settings:mlverifyssl_desc', 'local_proctorcore'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/mlconnecttimeout',
        get_string('settings:mlconnecttimeout', 'local_proctorcore'),
        get_string('settings:mlconnecttimeout_desc', 'local_proctorcore'),
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/mlrequesttimeout',
        get_string('settings:mlrequesttimeout', 'local_proctorcore'),
        get_string('settings:mlrequesttimeout_desc', 'local_proctorcore'),
        20,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_proctorcore/identityenabled',
        get_string('settings:identityenabled', 'local_proctorcore'),
        get_string('settings:identityenabled_desc', 'local_proctorcore'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/identitythreshold',
        get_string('settings:identitythreshold', 'local_proctorcore'),
        get_string('settings:identitythreshold_desc', 'local_proctorcore'),
        '0.45',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_proctorcore/monitoringenabled',
        get_string('settings:monitoringenabled', 'local_proctorcore'),
        get_string('settings:monitoringenabled_desc', 'local_proctorcore'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/monitorintervalms',
        get_string('settings:monitorintervalms', 'local_proctorcore'),
        get_string('settings:monitorintervalms_desc', 'local_proctorcore'),
        3000,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/nofaceseconds',
        get_string('settings:nofaceseconds', 'local_proctorcore'),
        get_string('settings:nofaceseconds_desc', 'local_proctorcore'),
        3,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/multiplefaceseconds',
        get_string('settings:multiplefaceseconds', 'local_proctorcore'),
        get_string('settings:multiplefaceseconds_desc', 'local_proctorcore'),
        3,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/lookawayseconds',
        get_string('settings:lookawayseconds', 'local_proctorcore'),
        get_string('settings:lookawayseconds_desc', 'local_proctorcore'),
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/violationcooldownseconds',
        get_string('settings:violationcooldownseconds', 'local_proctorcore'),
        get_string('settings:violationcooldownseconds_desc', 'local_proctorcore'),
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/identityrecheckseconds',
        get_string('settings:identityrecheckseconds', 'local_proctorcore'),
        get_string('settings:identityrecheckseconds_desc', 'local_proctorcore'),
        60,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'local_proctorcore/retentionheading',
        get_string('settings:retentionheading', 'local_proctorcore'),
        get_string('settings:retentionheading_desc', 'local_proctorcore')
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/reportretentiondays',
        get_string('settings:reportretentiondays', 'local_proctorcore'),
        get_string('settings:reportretentiondays_desc', 'local_proctorcore'),
        183,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/videoretentiondays',
        get_string('settings:videoretentiondays', 'local_proctorcore'),
        get_string('settings:videoretentiondays_desc', 'local_proctorcore'),
        30,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/appealperioddays',
        get_string('settings:appealperioddays', 'local_proctorcore'),
        get_string('settings:appealperioddays_desc', 'local_proctorcore'),
        14,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'local_proctorcore/recoveryheading',
        get_string('settings:recoveryheading', 'local_proctorcore'),
        get_string('settings:recoveryheading_desc', 'local_proctorcore')
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/defaultresumewindowsecs',
        get_string('settings:defaultresumewindowsecs', 'local_proctorcore'),
        get_string('settings:defaultresumewindowsecs_desc', 'local_proctorcore'),
        600,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/heartbeatintervalsecs',
        get_string('settings:heartbeatintervalsecs', 'local_proctorcore'),
        get_string('settings:heartbeatintervalsecs_desc', 'local_proctorcore'),
        15,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_proctorcore/heartbeatgracesecs',
        get_string('settings:heartbeatgracesecs', 'local_proctorcore'),
        get_string('settings:heartbeatgracesecs_desc', 'local_proctorcore'),
        45,
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}
