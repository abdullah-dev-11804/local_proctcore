<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

use local_proctorcore\form\participant_fields_form;
use local_proctorcore\local\participant_field_service;

require_login();
$context = context_system::instance();
require_capability('local/proctorcore:manageparticipantfields', $context);
$service = new participant_field_service();
$tenants = new \local_proctorcore\local\tenant_resolver();
$companyids = is_siteadmin() ? [] : $tenants->get_user_company_ids((int) $USER->id);
if (is_siteadmin()) {
    foreach (['company', 'local_iomad_companies'] as $table) {
        if ($DB->get_manager()->table_exists(new xmldb_table($table))) {
            $companyids = array_map('intval', $DB->get_fieldset_select($table, 'id', 'id > 0'));
            break;
        }
    }
}
if (!$companyids && !$tenants->is_iomad_available()) {
    $companyids = [0];
}
$companies = [];
foreach (array_unique($companyids) as $id) {
    $companies[$id] = $id ? get_string('report:companynumber', 'local_proctorcore', $id)
        : get_string('report:globalcompany', 'local_proctorcore');
}
if (!$companies) {
    throw new moodle_exception('companypolicy:nocompanies', 'local_proctorcore');
}
$companyid = optional_param('companyid', (int) array_key_first($companies), PARAM_INT);
if (!array_key_exists($companyid, $companies)) {
    throw new required_capability_exception($context, 'local/proctorcore:manageparticipantfields', 'nopermissions', '');
}
if (optional_param('delete', 0, PARAM_INT)) {
    require_sesskey();
    $id = required_param('delete', PARAM_INT);
    $field = $DB->get_record('local_proctorcore_fields', ['id' => $id, 'companyid' => $companyid], '*', MUST_EXIST);
    $service->delete_definition((int) $field->id);
    (new \local_proctorcore\local\audit_logger())->log('participant_field.deleted', $companyid, null, null,
        ['shortname' => $field->shortname], (int) $USER->id, 'participant_field', (int) $field->id);
    redirect(new moodle_url('/local/proctorcore/participant_fields.php', ['companyid' => $companyid]));
}
$profilefields = [0 => get_string('participant:profilefieldnone', 'local_proctorcore')];
if ($DB->get_manager()->table_exists(new xmldb_table('user_info_field'))) {
    foreach ($DB->get_records('user_info_field', [], 'sortorder, id', 'id,name,shortname') as $field) {
        $profilefields[(int) $field->id] = format_string($field->name) . ' (' . s($field->shortname) . ')';
    }
}
$PAGE->set_context($context);
$PAGE->set_url('/local/proctorcore/participant_fields.php', ['companyid' => $companyid]);
$PAGE->set_title(get_string('participant:manage', 'local_proctorcore'));
$PAGE->set_heading(get_string('participant:manage', 'local_proctorcore'));
$form = new participant_fields_form(null, ['companies' => $companies, 'profilefields' => $profilefields]);
$editid = optional_param('edit', 0, PARAM_INT);
if ($editid) {
    $record = $DB->get_record('local_proctorcore_fields', ['id' => $editid, 'companyid' => $companyid], '*', MUST_EXIST);
    $config = json_decode((string) $record->configjson, true);
    $record->options = implode("\n", $config['options'] ?? []);
    $form->set_data($record);
}
if ($data = $form->get_data()) {
    if (!array_key_exists((int) $data->companyid, $companies)) {
        throw new required_capability_exception($context, 'local/proctorcore:manageparticipantfields', 'nopermissions', '');
    }
    $saved = $service->save_definition((array) $data);
    (new \local_proctorcore\local\audit_logger())->log('participant_field.saved', (int) $data->companyid, null, null,
        ['shortname' => $saved->shortname, 'active' => (int) $saved->active], (int) $USER->id,
        'participant_field', (int) $saved->id);
    redirect(new moodle_url('/local/proctorcore/participant_fields.php', ['companyid' => (int) $data->companyid]),
        get_string('changessaved'));
}
echo $OUTPUT->header();
$table = new html_table();
$table->head = [get_string('participant:shortname', 'local_proctorcore'), get_string('participant:name', 'local_proctorcore'),
    get_string('participant:datatype', 'local_proctorcore'), get_string('participant:active', 'local_proctorcore'), get_string('actions')];
foreach ($service->get_fields($companyid, false) as $field) {
    $edit = new moodle_url('/local/proctorcore/participant_fields.php', ['companyid' => $companyid, 'edit' => $field->id]);
    $delete = new moodle_url('/local/proctorcore/participant_fields.php', [
        'companyid' => $companyid, 'delete' => $field->id, 'sesskey' => sesskey(),
    ]);
    $table->data[] = [s($field->shortname), format_string($field->name), s($field->datatype),
        $field->active ? get_string('yes') : get_string('no'),
        html_writer::link($edit, get_string('edit')) . ' | ' . html_writer::link($delete, get_string('delete'))];
}
echo html_writer::table($table);
$form->display();
echo $OUTPUT->footer();
