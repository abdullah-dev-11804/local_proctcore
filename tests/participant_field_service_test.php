<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/** Tests participant field definition lifecycle without removing report history. */
final class participant_field_service_test extends \advanced_testcase {
    public function test_definition_supports_localisation_options_and_safe_delete(): void {
        global $DB;
        $this->resetAfterTest();
        $service = new \local_proctorcore\local\participant_field_service();
        $record = $service->save_definition([
            'companyid' => 0, 'shortname' => 'region', 'name' => 'Region',
            'nameru' => 'Region RU', 'namekk' => 'Region KK',
            'datatype' => 'dropdown', 'options' => "North\nSouth", 'required' => 1,
            'editablebyuser' => 1, 'active' => 1, 'sortorder' => 10,
        ]);
        $this->assertSame('dropdown', $record->datatype);
        $this->assertSame(['North', 'South'], json_decode($record->configjson, true)['options']);
        $this->assertCount(1, $service->get_fields(0));

        $service->delete_definition((int) $record->id);
        $this->assertFalse($DB->record_exists('local_proctorcore_fields', ['id' => $record->id]));
    }
}
