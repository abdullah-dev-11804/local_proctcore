<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

use local_proctorcore\form\consent_document_form;
use local_proctorcore\local\consent_service;

require_login();
require_capability('local/proctorcore:manageconsent', context_system::instance());
$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/proctorcore/consent_documents.php');
$PAGE->set_title(get_string('consent:manage', 'local_proctorcore'));
$PAGE->set_heading(get_string('consent:manage', 'local_proctorcore'));

$form = new consent_document_form();
$editid = optional_param('edit', 0, PARAM_INT);
if ($editid) {
    $sql = "SELECT d.*, v.titlekk, v.titleru, v.titleen, v.contentkk, v.contentru, v.contenten
              FROM {local_proctorcore_consentdoc} d
              JOIN {local_proctorcore_consentver} v
                ON v.documentid = d.id AND v.version = d.currentversion
             WHERE d.id = :id";
    $record = $DB->get_record_sql($sql, ['id' => $editid], MUST_EXIST);
    $record->documentid = (int) $record->id;
    foreach (['kk', 'ru', 'en'] as $lang) {
        $record->{'content' . $lang} = ['text' => $record->{'content' . $lang}, 'format' => FORMAT_HTML];
    }
    $form->set_data($record);
}
if (($toggleid = optional_param('toggle', 0, PARAM_INT)) && confirm_sesskey()) {
    $document = $DB->get_record('local_proctorcore_consentdoc', ['id' => $toggleid], '*', MUST_EXIST);
    $DB->set_field('local_proctorcore_consentdoc', 'active', empty($document->active) ? 1 : 0, ['id' => $toggleid]);
    (new \local_proctorcore\local\audit_logger())->log('consent_document.toggled', 0, null, null,
        ['active' => empty($document->active) ? 1 : 0], (int) $USER->id, 'consent_document', $toggleid);
    redirect($PAGE->url, get_string('changessaved'));
}
if ($data = $form->get_data()) {
    $values = (array) $data;
    foreach (['kk', 'ru', 'en'] as $lang) {
        $values['content' . $lang] = $values['content' . $lang]['text'] ?? '';
    }
    $published = (new consent_service())->publish($values, (int) $USER->id);
    (new \local_proctorcore\local\audit_logger())->log('consent_document.published', 0, null, null,
        ['shortname' => $values['shortname']], (int) $USER->id, 'consent_document', (int) $published->id);
    redirect($PAGE->url, get_string('consent:published', 'local_proctorcore'));
}

$records = $DB->get_records('local_proctorcore_consentdoc', [], 'sortorder, id');
echo $OUTPUT->header();
if ($records) {
    $table = new html_table();
    $table->head = [get_string('consent:shortname', 'local_proctorcore'), get_string('consent:version', 'local_proctorcore'),
        get_string('consent:active', 'local_proctorcore'), get_string('actions')];
    foreach ($records as $record) {
        $edit = new moodle_url('/local/proctorcore/consent_documents.php', ['edit' => $record->id]);
        $toggle = new moodle_url('/local/proctorcore/consent_documents.php', ['toggle' => $record->id, 'sesskey' => sesskey()]);
        $table->data[] = [s($record->shortname), (int) $record->currentversion,
            $record->active ? get_string('yes') : get_string('no'),
            html_writer::link($edit, get_string('edit')) . ' | '
                . html_writer::link($toggle, $record->active ? get_string('disable') : get_string('enable'))];
    }
    echo html_writer::table($table);
}
$form->display();
echo $OUTPUT->footer();
