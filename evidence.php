<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

use local_proctorcore\local\asset_access_service;
use local_proctorcore\local\asset_repository;
use local_proctorcore\local\audit_logger;
use local_proctorcore\local\report_service;
use local_proctorcore\local\session_repository;

require_login();
$assetid = required_param('assetid', PARAM_INT);
$download = optional_param('download', 0, PARAM_BOOL);
$asset = (new asset_repository())->get_by_id($assetid);
$session = (new session_repository())->get_by_id((int) $asset->sessionid);
$reports = new report_service();
$reports->require_can_view_session($session, (int) $USER->id);

(new audit_logger())->log(
    $download ? 'report.evidence_downloaded' : 'report.evidence_viewed',
    (int) $session->companyid,
    (int) $session->id,
    (int) $session->userid,
    [
        'assetType' => (string) $asset->assettype,
        'storage' => (string) $asset->storage,
    ],
    (int) $USER->id,
    'asset',
    (int) $asset->id
);
(new asset_access_service())->serve($asset, $download);
