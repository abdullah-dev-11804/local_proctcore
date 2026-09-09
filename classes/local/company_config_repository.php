<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads tenant-aware ProctorCore integration configuration.
 *
 * Company-specific values override global plugin settings. Empty company
 * values fall back to the global configuration.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class company_config_repository {
    /** Database table. */
    private const TABLE = 'local_proctorcore_companycfg';

    /**
     * Returns the effective integration configuration for a company.
     *
     * @param int $companyid IOMAD company id, or 0 for global Moodle scope.
     * @return \stdClass
     */
    public function get_effective_config(int $companyid, ?int $quizid = null): \stdClass {
        global $DB;

        $global = (object) [
            'companyid' => $companyid,
            'enabled' => (bool) $this->global_config('enabled', 0),
            'serverbaseurl' => trim((string) $this->global_config('serverbaseurl', '')),
            'serverapikey' => (string) $this->global_config('serverapikey', ''),
            'webhooksecret' => (string) $this->global_config('webhooksecret', ''),
            'connecttimeout' => max(1, (int) $this->global_config('connecttimeout', 5)),
            'requesttimeout' => max(1, (int) $this->global_config('requesttimeout', 20)),
            'verifyssl' => (bool) $this->global_config('verifyssl', 1),
            'livekitclienturl' => trim((string) $this->global_config('livekitclienturl', '')),
            'minimumspeedmbps' => max(0.1, (float) $this->global_config('minimumspeedmbps', 5.0)),
            'minimumlighting' => min(255, max(1, (int) $this->global_config('minimumlighting', 35))),
            'identityenabled' => (bool) $this->global_config('identityenabled', 1),
            'identitythreshold' => min(1.0, max(0.85, (float) $this->global_config('identitythreshold', 0.85))),
            'identityminliveframes' => min(12, max(1, (int) $this->global_config('identityminliveframes', 1))),
            'identityminbrightness' => min(255, max(1, (float) $this->global_config('identityminbrightness', 35))),
            'identityminblur' => min(500, max(1, (float) $this->global_config('identityminblur', 35))),
            'identityminfaceconfidence' => min(1.0, max(0.0, (float) $this->global_config('identityminfaceconfidence', 0.65))),
            'identityminenrollmentframes' => min(12, max(1, (int) $this->global_config('identityminenrollmentframes', 3))),
            'identitymintemplateconsistency' => min(1.0, max(0.0, (float) $this->global_config('identitymintemplateconsistency', 0.35))),
            'identityenrollmentminbrightness' => min(255, max(1, (float) $this->global_config('identityenrollmentminbrightness', 45))),
            'identityenrollmentminblur' => min(500, max(1, (float) $this->global_config('identityenrollmentminblur', 45))),
            'identityenrollmentminfaceconfidence' => min(1.0, max(0.0, (float) $this->global_config('identityenrollmentminfaceconfidence', 0.75))),
            'identityenrollmentminfacewidthratio' => min(1.0, max(0.01, (float) $this->global_config('identityenrollmentminfacewidthratio', 0.18))),
            'identityenrollmentmaxfacewidthratio' => min(1.0, max(0.01, (float) $this->global_config('identityenrollmentmaxfacewidthratio', 0.58))),
            'identityenrollmentcentertolerancex' => min(0.5, max(0.01, (float) $this->global_config('identityenrollmentcentertolerancex', 0.18))),
            'identityenrollmentcentertolerancey' => min(0.5, max(0.01, (float) $this->global_config('identityenrollmentcentertolerancey', 0.23))),
            'identitymismatchmode' => $this->normalise_mismatch_mode(
                (string) $this->global_config('identitymismatchmode', 'review')
            ),
            'monitoringenabled' => (bool) $this->global_config('monitoringenabled', 1),
            'monitorintervalms' => min(30000, max(1500, (int) $this->global_config('monitorintervalms', 3000))),
            'nofaceseconds' => min(60, max(1, (int) $this->global_config('nofaceseconds', 3))),
            'multiplefaceseconds' => min(60, max(1, (int) $this->global_config('multiplefaceseconds', 3))),
            'lookawayseconds' => min(120, max(1, (int) $this->global_config('lookawayseconds', 5))),
            'spoofseconds' => min(30, max(1, (int) $this->global_config('spoofseconds', 2))),
            'violationcooldownseconds' => min(600, max(5, (int) $this->global_config('violationcooldownseconds', 30))),
            'identityrecheckseconds' => min(3600, max(15, (int) $this->global_config('identityrecheckseconds', 60))),
            'reportretentiondays' => max(183, (int) $this->global_config('reportretentiondays', 183)),
            'videoretentiondays' => max(1, (int) $this->global_config('videoretentiondays', 30)),
            'appealperioddays' => max(1, (int) $this->global_config('appealperioddays', 14)),
            'allowedlanguages' => 'en,ru,kk',
            'featureflags' => null,
            'instructions' => null,
        ];

        if ($companyid <= 0 || !$DB->record_exists(self::TABLE, ['companyid' => $companyid])) {
            return $this->apply_quiz_override($global, $quizid, $companyid);
        }

        $company = $DB->get_record(self::TABLE, ['companyid' => $companyid], '*', MUST_EXIST);
        $global->enabled = (bool) $company->enabled;
        $global->serverbaseurl = trim((string) ($company->serverbaseurl ?: $global->serverbaseurl));
        $global->reportretentiondays = max(183, (int) $company->reportretentiondays);
        $global->videoretentiondays = max(1, (int) $company->videoretentiondays);
        $global->appealperioddays = max(1, (int) $company->appealperioddays);
        if (property_exists($company, 'identitymismatchmode') && trim((string) $company->identitymismatchmode) !== '') {
            $global->identitymismatchmode = $this->normalise_mismatch_mode((string) $company->identitymismatchmode);
        }
        if (property_exists($company, 'identitythreshold') && $company->identitythreshold !== null) {
            $global->identitythreshold = min(1.0, max(0.85, (float) $company->identitythreshold));
        }
        $global->allowedlanguages = (string) $company->allowedlanguages;
        $global->featureflags = $company->featureflags;
        $global->instructions = $company->instructions;

        return $this->apply_quiz_override($global, $quizid, $companyid);
    }

    /**
     * Ensures the integration is configured and enabled.
     *
     * @param int $companyid Company id.
     * @return \stdClass Effective configuration.
     */
    public function require_enabled_config(int $companyid): \stdClass {
        $config = $this->get_effective_config($companyid);

        if (!$config->enabled) {
            throw new \moodle_exception('error:integrationdisabled', 'local_proctorcore');
        }
        if ($config->serverbaseurl === '') {
            throw new \moodle_exception('error:serverurlmissing', 'local_proctorcore');
        }
        if (!preg_match('~^https://~i', $config->serverbaseurl) && $config->verifyssl) {
            throw new \moodle_exception('error:httpsrequired', 'local_proctorcore');
        }

        return $config;
    }

    /**
     * Saves company-owned identity and retention policy values.
     *
     * @param int $companyid IOMAD company id.
     * @param array $values Validated policy values.
     * @param int $userid Administrator making the change.
     * @return \stdClass Saved row.
     */
    public function save_company_policy(int $companyid, array $values, int $userid): \stdClass {
        global $DB;

        if ($companyid <= 0) {
            throw new \coding_exception('A positive IOMAD company id is required.');
        }
        $mode = $this->normalise_mismatch_mode((string) ($values['identitymismatchmode'] ?? 'review'));
        $threshold = min(1.0, max(0.85, (float) ($values['identitythreshold'] ?? 0.85)));
        $now = time();
        $record = $DB->get_record(self::TABLE, ['companyid' => $companyid]);
        if (!$record) {
            $record = (object) [
                'companyid' => $companyid,
                'enabled' => 1,
                'serverbaseurl' => null,
                'webhooksecretref' => null,
                'allowedlanguages' => 'en,ru,kk',
                'featureflags' => null,
                'instructions' => null,
                'timecreated' => $now,
            ];
        }
        $record->identitymismatchmode = $mode;
        $record->identitythreshold = $threshold;
        $record->reportretentiondays = max(183, (int) ($values['reportretentiondays'] ?? 183));
        $record->videoretentiondays = max(1, (int) ($values['videoretentiondays'] ?? 30));
        $record->appealperioddays = max(1, (int) ($values['appealperioddays'] ?? 14));
        $record->timemodified = $now;
        $record->usermodified = $userid > 0 ? $userid : null;

        if (!empty($record->id)) {
            $DB->update_record(self::TABLE, $record);
        } else {
            $record->id = $DB->insert_record(self::TABLE, $record);
        }
        return $record;
    }

    /**
     * Reads a global setting without confusing an explicit zero with a missing value.
     *
     * @param string $name Setting name.
     * @param mixed $default Default used only when the setting does not exist.
     * @return mixed
     */
    private function global_config(string $name, $default) {
        $value = get_config('local_proctorcore', $name);
        return $value === false ? $default : $value;
    }

    /** @return string */
    private function normalise_mismatch_mode(string $mode): string {
        $mode = clean_param($mode, PARAM_ALPHANUMEXT);
        return in_array($mode, ['block', 'review', 'fail'], true) ? $mode : 'review';
    }

    /** Apply the most-specific Quiz override after company/global policy. */
    private function apply_quiz_override(\stdClass $config, ?int $quizid, int $companyid): \stdClass {
        global $DB;

        if (!$quizid) {
            return $config;
        }
        $quizconfig = $DB->get_record('local_proctorcore_quizcfg', [
            'companyid' => $companyid,
            'quizid' => $quizid,
        ]);
        if (!$quizconfig && $companyid !== 0) {
            $quizconfig = $DB->get_record('local_proctorcore_quizcfg', [
                'companyid' => 0,
                'quizid' => $quizid,
            ]);
        }
        if (!$quizconfig) {
            return $config;
        }
        if (property_exists($quizconfig, 'identitymismatchmode')
                && trim((string) $quizconfig->identitymismatchmode) !== '') {
            $config->identitymismatchmode = $this->normalise_mismatch_mode((string) $quizconfig->identitymismatchmode);
        }
        if (property_exists($quizconfig, 'identitythreshold') && $quizconfig->identitythreshold !== null) {
            $config->identitythreshold = min(1.0, max(0.85, (float) $quizconfig->identitythreshold));
        }
        return $config;
    }

}
