<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Student form for submitting a proctoring appeal. */
final class appeal_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'sessionid', (int) $this->_customdata['sessionid']);
        $mform->setType('sessionid', PARAM_INT);
        $mform->addElement('select', 'reason', get_string('appeal:reason', 'local_proctorcore'), [
            'technical_failure' => get_string('appeal:technicalfailure', 'local_proctorcore'),
            'identification_error' => get_string('appeal:identificationerror', 'local_proctorcore'),
            'other' => get_string('appeal:other', 'local_proctorcore'),
        ]);
        $mform->setType('reason', PARAM_ALPHANUMEXT);
        $mform->addElement('textarea', 'details', get_string('appeal:details', 'local_proctorcore'), ['rows' => 7]);
        $mform->setType('details', PARAM_TEXT);
        $mform->addRule('details', null, 'required');
        $this->add_action_buttons(true, get_string('appeal:submit', 'local_proctorcore'));
    }
}
