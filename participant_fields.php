<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

use local_proctorcore\form\participant_fields_form;
use local_proctorcore\local\audit_logger;
use local_proctorcore\local\participant_field_service;

require_login();
$context = context_system::instance();
require_capability('local/proctorcore:manageparticipantfields', $context);

$service = new participant_field_service();
$pageurl = new moodle_url('/local/proctorcore/participant_fields.php');
$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('participant:manage', 'local_proctorcore'));
$PAGE->set_heading(get_string('participant:manage', 'local_proctorcore'));

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
if ($action !== '') {
    require_sesskey();
    $audit = new audit_logger();
    if ($action === 'include') {
        $saved = $service->include_profile_field($id);
        $audit->log('participant_field.included', 0, null, null,
            ['shortname' => $saved->shortname], (int) $USER->id, 'participant_field', (int) $saved->id);
    } else if ($action === 'remove') {
        $field = $DB->get_record('local_proctorcore_fields', ['id' => $id, 'companyid' => 0], '*', MUST_EXIST);
        $service->delete_definition((int) $field->id);
        $audit->log('participant_field.removed', 0, null, null,
            ['shortname' => $field->shortname], (int) $USER->id, 'participant_field', (int) $field->id);
    } else if ($action === 'up' || $action === 'down') {
        $service->move_definition($id, $action);
        $audit->log('participant_field.reordered', 0, null, null,
            ['direction' => $action], (int) $USER->id, 'participant_field', $id);
    }
    redirect($pageurl, get_string('changessaved'));
}

$profilefields = $service->get_profile_fields();
$profileoptions = [0 => get_string('participant:profilefieldnone', 'local_proctorcore')];
foreach ($profilefields as $profilefield) {
    $profileoptions[(int) $profilefield->id] = format_string($profilefield->name)
        . ' (' . s($profilefield->shortname) . ')';
}

$editid = optional_param('edit', 0, PARAM_INT);
$form = null;
if ($editid) {
    $record = $DB->get_record('local_proctorcore_fields', ['id' => $editid, 'companyid' => 0], '*', MUST_EXIST);
    $config = json_decode((string) $record->configjson, true);
    $record->options = implode("\n", $config['options'] ?? []);
    $form = new participant_fields_form(null, ['profilefields' => $profileoptions]);
    $form->set_data($record);
    if ($form->is_cancelled()) {
        redirect($pageurl);
    }
    if ($data = $form->get_data()) {
        $data->companyid = 0;
        $saved = $service->save_definition((array) $data);
        (new audit_logger())->log('participant_field.saved', 0, null, null,
            ['shortname' => $saved->shortname, 'active' => (int) $saved->active], (int) $USER->id,
            'participant_field', (int) $saved->id);
        redirect($pageurl, get_string('changessaved'));
    }
}

echo $OUTPUT->header();
echo $OUTPUT->notification(get_string('participant:managedescription', 'local_proctorcore'), 'info', false);
echo html_writer::div(
    html_writer::link(
        new moodle_url('/user/profile/index.php'),
        get_string('participant:manageprofilefields', 'local_proctorcore'),
        ['class' => 'btn btn-secondary']
    ),
    'mb-3'
);

if ($form) {
    echo $OUTPUT->heading(get_string('participant:editconfiguration', 'local_proctorcore'), 3);
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

$configured = array_values(array_filter(
    $service->get_fields(0, false),
    static fn($field): bool => !empty($field->active)
));
$profilebyid = [];
foreach ($profilefields as $profilefield) {
    $profilebyid[(int) $profilefield->id] = $profilefield;
}
$selectedids = array_fill_keys(array_map(static fn($field): int => (int) $field->profilefieldid, $configured), true);

echo $OUTPUT->heading(get_string('participant:selectedfields', 'local_proctorcore'), 3);
$selectedtable = new html_table();
$selectedtable->attributes['class'] = 'generaltable local-proctorcore-participant-board';
$selectedtable->head = [
    get_string('participant:order', 'local_proctorcore'),
    get_string('participant:name', 'local_proctorcore'),
    get_string('participant:profilefield', 'local_proctorcore'),
    get_string('participant:datatype', 'local_proctorcore'),
    get_string('participant:required', 'local_proctorcore'),
    get_string('participant:editable', 'local_proctorcore'),
    get_string('actions'),
];
foreach ($configured as $position => $field) {
    $actionlinks = [];
    if ($position > 0) {
        $actionlinks[] = html_writer::link(new moodle_url($pageurl, [
            'action' => 'up', 'id' => $field->id, 'sesskey' => sesskey(),
        ]), get_string('moveup'));
    }
    if ($position < count($configured) - 1) {
        $actionlinks[] = html_writer::link(new moodle_url($pageurl, [
            'action' => 'down', 'id' => $field->id, 'sesskey' => sesskey(),
        ]), get_string('movedown'));
    }
    $actionlinks[] = html_writer::link(new moodle_url($pageurl, ['edit' => $field->id]), get_string('edit'));
    $actionlinks[] = html_writer::link(new moodle_url($pageurl, [
        'action' => 'remove', 'id' => $field->id, 'sesskey' => sesskey(),
    ]), get_string('participant:remove', 'local_proctorcore'));

    $mapped = $profilebyid[(int) $field->profilefieldid] ?? null;
    $mappedname = $mapped
        ? format_string($mapped->name) . ' (' . s($mapped->shortname) . ')'
        : get_string('participant:missingprofilefield', 'local_proctorcore');
    $selectedtable->data[] = [
        (string) ($position + 1),
        format_string($field->name),
        $mappedname,
        get_string('participant:type_' . $field->datatype, 'local_proctorcore'),
        $field->required ? get_string('yes') : get_string('no'),
        $field->editablebyuser ? get_string('yes') : get_string('no'),
        implode(' | ', $actionlinks),
    ];
}
if ($selectedtable->data) {
    echo html_writer::table($selectedtable);
} else {
    echo $OUTPUT->notification(get_string('participant:noselectedfields', 'local_proctorcore'), 'info', false);
}

echo $OUTPUT->heading(get_string('participant:availablefields', 'local_proctorcore'), 3);
$availabletable = new html_table();
$availabletable->attributes['class'] = 'generaltable local-proctorcore-participant-available';
$availabletable->head = [
    get_string('participant:profilefield', 'local_proctorcore'),
    get_string('participant:shortname', 'local_proctorcore'),
    get_string('participant:datatype', 'local_proctorcore'),
    get_string('participant:requiredinprofile', 'local_proctorcore'),
    get_string('actions'),
];
foreach ($profilefields as $profilefield) {
    $included = isset($selectedids[(int) $profilefield->id]);
    $actionlink = $included
        ? get_string('participant:included', 'local_proctorcore')
        : html_writer::link(new moodle_url($pageurl, [
            'action' => 'include', 'id' => $profilefield->id, 'sesskey' => sesskey(),
        ]), get_string('participant:include', 'local_proctorcore'));
    $availabletable->data[] = [
        format_string($profilefield->name),
        s($profilefield->shortname),
        s($profilefield->datatype),
        !empty($profilefield->required) ? get_string('yes') : get_string('no'),
        $actionlink,
    ];
}
if ($availabletable->data) {
    echo html_writer::table($availabletable);
} else {
    echo $OUTPUT->notification(get_string('participant:noprofilefields', 'local_proctorcore'), 'info', false);
}

echo $OUTPUT->footer();
