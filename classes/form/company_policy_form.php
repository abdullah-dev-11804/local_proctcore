<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/** Company-scoped identity and retention policy editor. */
final class company_policy_form extends \moodleform {
    /** @return void */
    protected function definition(): void {
        $mform = $this->_form;
        $companies = $this->_customdata['companies'];

        $mform->addElement('select', 'companyid', get_string('companypolicy:company', 'local_proctorcore'), $companies);
        $mform->setType('companyid', PARAM_INT);
        $mform->addElement('select', 'identitymismatchmode',
            get_string('settings:identitymismatchmode', 'local_proctorcore'), [
                'block' => get_string('settings:identitymismatchmode_block', 'local_proctorcore'),
                'review' => get_string('settings:identitymismatchmode_review', 'local_proctorcore'),
                'fail' => get_string('settings:identitymismatchmode_fail', 'local_proctorcore'),
            ]);
        $mform->setType('identitymismatchmode', PARAM_ALPHANUMEXT);
        $mform->addElement('text', 'identitythreshold',
            get_string('settings:identitythreshold', 'local_proctorcore'), ['size' => 8]);
        $mform->setType('identitythreshold', PARAM_FLOAT);
        foreach (['reportretentiondays', 'videoretentiondays', 'appealperioddays'] as $field) {
            $mform->addElement('text', $field, get_string('settings:' . $field, 'local_proctorcore'), ['size' => 8]);
            $mform->setType($field, PARAM_INT);
        }
        $this->add_action_buttons(false, get_string('savechanges'));
    }

    /** @return array */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!isset($this->_customdata['companies'][(int) ($data['companyid'] ?? 0)])) {
            $errors['companyid'] = get_string('companypolicy:invalidcompany', 'local_proctorcore');
        }
        $threshold = (float) ($data['identitythreshold'] ?? 0);
        if ($threshold < 0.85 || $threshold > 1.0) {
            $errors['identitythreshold'] = get_string('companypolicy:invalidthreshold', 'local_proctorcore');
        }
        if ((int) ($data['reportretentiondays'] ?? 0) < 183) {
            $errors['reportretentiondays'] = get_string('companypolicy:invalidreportretention', 'local_proctorcore');
        }
        foreach (['videoretentiondays', 'appealperioddays'] as $field) {
            if ((int) ($data[$field] ?? 0) < 1) {
                $errors[$field] = get_string('companypolicy:invaliddays', 'local_proctorcore');
            }
        }
        return $errors;
    }
}
