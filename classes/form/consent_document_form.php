<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Publishes an immutable multilingual consent-document version. */
final class consent_document_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'documentid', 0);
        $mform->setType('documentid', PARAM_INT);
        $mform->addElement('text', 'shortname', get_string('consent:shortname', 'local_proctorcore'));
        $mform->setType('shortname', PARAM_ALPHANUMEXT);
        $mform->addRule('shortname', null, 'required');
        foreach (['kk', 'ru', 'en'] as $lang) {
            $mform->addElement('header', 'language' . $lang, strtoupper($lang));
            $mform->addElement('text', 'title' . $lang, get_string('consent:titlefield', 'local_proctorcore'));
            $mform->setType('title' . $lang, PARAM_TEXT);
            $mform->addRule('title' . $lang, null, 'required');
            $mform->addElement('editor', 'content' . $lang, get_string('consent:contentfield', 'local_proctorcore'));
            $mform->setType('content' . $lang, PARAM_RAW);
            $mform->addRule('content' . $lang, null, 'required');
        }
        $mform->addElement('advcheckbox', 'required', get_string('consent:required', 'local_proctorcore'));
        $mform->setDefault('required', 1);
        $mform->addElement('advcheckbox', 'active', get_string('consent:active', 'local_proctorcore'));
        $mform->addElement('advcheckbox', 'materialchange', get_string('consent:materialchange', 'local_proctorcore'));
        $mform->setDefault('materialchange', 1);
        $mform->addElement('text', 'sortorder', get_string('consent:sortorder', 'local_proctorcore'));
        $mform->setType('sortorder', PARAM_INT);
        $this->add_action_buttons(false, get_string('consent:publish', 'local_proctorcore'));
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        foreach (['kk', 'ru', 'en'] as $lang) {
            $content = $data['content' . $lang]['text'] ?? '';
            if (trim(html_to_text($content, 0, false)) === '') {
                $errors['content' . $lang] = get_string('required');
            }
        }
        return $errors;
    }
}
