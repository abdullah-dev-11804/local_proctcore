<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/** Tests immutable consent versions and renewed-consent detection. */
final class consent_service_test extends \advanced_testcase {
    public function test_publishing_material_version_requires_fresh_consent(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $service = new \local_proctorcore\local\consent_service();
        $values = [
            'shortname' => 'privacy', 'active' => 1, 'required' => 1, 'sortorder' => 1,
            'materialchange' => 1,
            'titlekk' => 'Privacy KK', 'titleru' => 'Privacy RU', 'titleen' => 'Privacy EN',
            'contentkk' => '<p>KK v1</p>', 'contentru' => '<p>RU v1</p>', 'contenten' => '<p>EN v1</p>',
        ];
        $document = $service->publish($values, (int) $user->id);
        $documents = $service->get_required_documents();
        $this->assertCount(1, $documents);
        $this->assertFalse($service->has_current_consent((int) $user->id));
        $service->accept((int) $user->id, $service->documents_hash($documents), 'en');
        $this->assertTrue($service->has_current_consent((int) $user->id));

        $values['documentid'] = (int) $document->id;
        $values['contenten'] = '<p>EN v2</p>';
        $service->publish($values, (int) $user->id);

        $this->assertFalse($service->has_current_consent((int) $user->id));
        $this->assertSame(2, $DB->count_records('local_proctorcore_consentver'));
        $this->assertSame(1, $DB->count_records('local_proctorcore_consentlog'));
    }
}
