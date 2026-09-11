<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Company-scoped participant field definition editor. */
final class participant_fields_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('select', 'companyid', get_string('participant:company', 'local_proctorcore'),
            $this->_customdata['companies']);
        $mform->setType('companyid', PARAM_INT);
        $mform->addElement('text', 'shortname', get_string('participant:shortname', 'local_proctorcore'));
        $mform->setType('shortname', PARAM_ALPHANUMEXT);
        $mform->addRule('shortname', null, 'required');
        foreach (['name', 'nameru', 'namekk'] as $name) {
            $mform->addElement('text', $name, get_string('participant:' . $name, 'local_proctorcore'));
            $mform->setType($name, PARAM_TEXT);
            $mform->addRule($name, null, 'required');
        }
        foreach (['helptext', 'helpru', 'helpkk'] as $name) {
            $mform->addElement('textarea', $name, get_string('participant:' . $name, 'local_proctorcore'));
            $mform->setType($name, PARAM_TEXT);
        }
        $mform->addElement('select', 'datatype', get_string('participant:datatype', 'local_proctorcore'), [
            'text' => get_string('participant:type_text', 'local_proctorcore'),
            'number' => get_string('participant:type_number', 'local_proctorcore'),
            'date' => get_string('participant:type_date', 'local_proctorcore'),
            'dropdown' => get_string('participant:type_dropdown', 'local_proctorcore'),
            'checkbox' => get_string('participant:type_checkbox', 'local_proctorcore'),
        ]);
        $mform->addElement('textarea', 'options', get_string('participant:options', 'local_proctorcore'));
        $mform->setType('options', PARAM_TEXT);
        $mform->hideIf('options', 'datatype', 'neq', 'dropdown');
        $mform->addElement('select', 'profilefieldid', get_string('participant:profilefield', 'local_proctorcore'),
            $this->_customdata['profilefields']);
        $mform->setType('profilefieldid', PARAM_INT);
        $mform->addElement('advcheckbox', 'required', get_string('participant:required', 'local_proctorcore'));
        $mform->addElement('advcheckbox', 'editablebyuser', get_string('participant:editable', 'local_proctorcore'));
        $mform->setDefault('editablebyuser', 1);
        $mform->addElement('advcheckbox', 'active', get_string('participant:active', 'local_proctorcore'));
        $mform->setDefault('active', 1);
        $mform->addElement('text', 'sortorder', get_string('participant:sortorder', 'local_proctorcore'));
        $mform->setType('sortorder', PARAM_INT);
        $this->add_action_buttons(false, get_string('savechanges'));
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!isset($this->_customdata['companies'][(int) ($data['companyid'] ?? 0)])) {
            $errors['companyid'] = get_string('participant:invalidcompany', 'local_proctorcore');
        }
        if (($data['datatype'] ?? '') === 'dropdown') {
            $options = preg_split('/\R+/', trim((string) ($data['options'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
            if (!$options) {
                $errors['options'] = get_string('participant:optionsrequired', 'local_proctorcore');
            }
        }
        return $errors;
    }
}
