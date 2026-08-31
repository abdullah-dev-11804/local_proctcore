<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

use local_proctorcore\local\asset_access_service;
use local_proctorcore\local\audit_logger;
use local_proctorcore\local\report_pdf_service;
use local_proctorcore\local\report_service;

require_login();
$sessionid = required_param('sessionid', PARAM_INT);
$reports = new report_service();
$session = $reports->get_session_record($sessionid);
if (!$reports->can_download_session($session, (int) $USER->id)) {
    throw new required_capability_exception(
        context_system::instance(),
        'local/proctorcore:exportreports',
        'nopermissions',
        ''
    );
}

$asset = (new report_pdf_service())->get_or_generate($sessionid, (int) $USER->id);
(new audit_logger())->log(
    'report.downloaded',
    (int) $session->companyid,
    (int) $session->id,
    (int) $session->userid,
    ['assetId' => (int) $asset->id],
    (int) $USER->id,
    'asset',
    (int) $asset->id
);
$file = (new asset_access_service())->get_moodle_file($asset);
send_stored_file($file, 0, 0, true);
