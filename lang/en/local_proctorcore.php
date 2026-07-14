<?php
// This file is part of Moodle - http://moodle.org/

$string['pluginname'] = 'ProctorCore';
$string['privacy:metadata'] = 'ProctorCore stores proctoring session records, report links, violations, appeals, participant fields, and audit events.';
$string['proctorcore:manage'] = 'Manage proctoring settings';
$string['proctorcore:viewownreport'] = 'View own proctoring report';
$string['proctorcore:viewcompanyreports'] = 'View company proctoring reports';
$string['proctorcore:viewallreports'] = 'View all company proctoring reports';
$string['proctorcore:reviewappeals'] = 'Review proctoring appeals';
$string['proctorcore:exportreports'] = 'Export proctoring reports';
$string['proctorcore:viewaudit'] = 'View proctoring audit log';
$string['settings:serverbaseurl'] = 'Server B base URL';
$string['settings:serverbaseurl_desc'] = 'Base URL for the external proctoring engine.';
$string['settings:webhooksecret'] = 'Webhook secret';
$string['settings:webhooksecret_desc'] = 'Shared secret used to validate signed Server B webhooks.';
$string['settings:reportretentiondays'] = 'PDF report retention days';
$string['settings:videoretentiondays'] = 'Video retention days';
$string['settings:appealperioddays'] = 'Appeal period days';
$string['task:cleanup_retention'] = 'Clean expired proctoring evidence links';
$string['privacy:metadata:sessions'] = 'Stores official proctoring session records linked to quiz attempts.';
$string['privacy:metadata:sessions:userid'] = 'The user whose quiz attempt was proctored.';
$string['privacy:metadata:sessions:result'] = 'The final proctoring result returned by Server B.';
$string['privacy:metadata:sessions:servermetadata'] = 'Additional Server B session metadata.';
$string['privacy:metadata:violations'] = 'Stores detected and manually flagged proctoring violations.';
$string['privacy:metadata:violations:userid'] = 'The user associated with the violation.';
$string['privacy:metadata:violations:type'] = 'The category of violation.';
$string['privacy:metadata:violations:description'] = 'A human-readable violation description.';
$string['privacy:metadata:appeals'] = 'Stores user appeals against proctoring outcomes.';
$string['privacy:metadata:appeals:userid'] = 'The user who filed the appeal.';
$string['privacy:metadata:appeals:reason'] = 'The selected appeal reason.';
$string['privacy:metadata:appeals:details'] = 'The free-text appeal explanation.';
