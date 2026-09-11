<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

use local_proctorcore\local\consent_service;

require_login();
$service = new consent_service();
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
$returnurl = $returnurl !== '' ? new moodle_url($returnurl) : new moodle_url('/my/');

if ($service->is_impersonating()) {
    redirect($returnurl);
}
if ($service->has_current_consent((int) $USER->id)) {
    redirect($returnurl);
}

$documents = $service->get_required_documents();
$hash = $service->documents_hash($documents);
if (data_submitted() && confirm_sesskey()) {
    if (!optional_param('accept', 0, PARAM_BOOL)) {
        redirect(new moodle_url('/local/proctorcore/consent.php', ['returnurl' => $returnurl->out_as_local_url(false)]),
            get_string('consent:mustaccept', 'local_proctorcore'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $service->accept((int) $USER->id, required_param('documentshash', PARAM_ALPHANUM), current_language());
    redirect($returnurl, get_string('consent:accepted', 'local_proctorcore'));
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/proctorcore/consent.php', ['returnurl' => $returnurl->out_as_local_url(false)]);
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('consent:title', 'local_proctorcore'));
$PAGE->set_heading(get_string('consent:title', 'local_proctorcore'));

echo $OUTPUT->header();
echo html_writer::start_div('local-proctorcore-consent');
echo $OUTPUT->heading(get_string('consent:title', 'local_proctorcore'));
echo html_writer::tag('p', get_string('consent:introduction', 'local_proctorcore'));
foreach ($documents as $index => $document) {
    $text = $service->localise($document, current_language());
    $id = 'proctorcore-consent-document-' . (int) $document->documentid;
    echo html_writer::link('#' . $id, format_string($text['title']), ['class' => 'd-block mb-2']);
    echo html_writer::start_tag('details', ['id' => $id, 'class' => 'mb-3', 'open' => $index === 0 ? 'open' : null]);
    echo html_writer::tag('summary', format_string($text['title']));
    echo html_writer::div(format_text($text['content'], FORMAT_HTML), 'mt-2');
    echo html_writer::end_tag('details');
}
echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'documentshash', 'value' => $hash]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'returnurl', 'value' => $returnurl->out_as_local_url(false)]);
echo html_writer::div(html_writer::checkbox('accept', 1, false,
    get_string('consent:confirmation', 'local_proctorcore'), ['required' => 'required']), 'mb-3');
echo html_writer::tag('button', get_string('consent:continue', 'local_proctorcore'), [
    'type' => 'submit', 'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo $OUTPUT->footer();
