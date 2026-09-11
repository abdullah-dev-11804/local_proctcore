<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('local/proctorcore:exportconsent', context_system::instance());

$filename = clean_filename('proctorcore-consent-log-' . gmdate('Ymd-His') . '.csv');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$output = fopen('php://output', 'wb');
fputcsv($output, ['user_id', 'company_id', 'language', 'document_versions', 'document_set_hash', 'ip_address', 'user_agent', 'consented_at_utc']);
$records = $DB->get_recordset('local_proctorcore_consentlog', [], 'id ASC');
foreach ($records as $record) {
    fputcsv($output, [(int) $record->userid, (int) $record->companyid, $record->language,
        $record->documentversions, $record->documentshash, $record->ipaddress, $record->useragent,
        gmdate('c', (int) $record->consentedat)]);
}
$records->close();
fclose($output);
exit;
