<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/proctorcore:manage', $context);

$tenants = new \local_proctorcore\local\tenant_resolver();
$companyids = $tenants->get_user_company_ids((int) $USER->id);
if (is_siteadmin()) {
    $companyids = [];
    foreach (['company', 'local_iomad_companies'] as $table) {
        if ($DB->get_manager()->table_exists(new xmldb_table($table))) {
            $companyids = array_map('intval', $DB->get_fieldset_select($table, 'id', 'id > 0'));
            break;
        }
    }
}
$companyids = array_values(array_unique(array_filter($companyids)));
sort($companyids, SORT_NUMERIC);
if (!$companyids) {
    throw new moodle_exception('companypolicy:nocompanies', 'local_proctorcore');
}

$companies = [];
foreach ($companyids as $id) {
    $companies[$id] = get_string('report:companynumber', 'local_proctorcore', $id);
}
$repository = new \local_proctorcore\local\company_config_repository();
$requested = optional_param('companyid', (int) reset($companyids), PARAM_INT);
if (!isset($companies[$requested])) {
    throw new moodle_exception('companypolicy:invalidcompany', 'local_proctorcore');
}
$effective = $repository->get_effective_config($requested);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/proctorcore/company_policy.php', ['companyid' => $requested]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('companypolicy:title', 'local_proctorcore'));
$PAGE->set_heading(get_string('companypolicy:title', 'local_proctorcore'));

$form = new \local_proctorcore\form\company_policy_form(null, ['companies' => $companies]);
if ($data = $form->get_data()) {
    $companyid = (int) $data->companyid;
    if (!isset($companies[$companyid])) {
        throw new required_capability_exception($context, 'local/proctorcore:manage', 'nopermissions', '');
    }
    $repository->save_company_policy($companyid, (array) $data, (int) $USER->id);
    (new \local_proctorcore\local\audit_logger())->log(
        'company_policy.updated', $companyid, null, null,
        ['identityMismatchMode' => $data->identitymismatchmode, 'identityThreshold' => $data->identitythreshold],
        (int) $USER->id, 'company', $companyid
    );
    redirect(new moodle_url('/local/proctorcore/company_policy.php', ['companyid' => $companyid]),
        get_string('changessaved'));
}
$form->set_data((object) [
    'companyid' => $requested,
    'identitymismatchmode' => $effective->identitymismatchmode,
    'identitythreshold' => $effective->identitythreshold,
    'reportretentiondays' => $effective->reportretentiondays,
    'videoretentiondays' => $effective->videoretentiondays,
    'appealperioddays' => $effective->appealperioddays,
]);

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();
