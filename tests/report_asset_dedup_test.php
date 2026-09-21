<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/** Tests checksum-based report presentation for duplicate video evidence. */
final class report_asset_dedup_test extends \advanced_testcase {
    public function test_identical_video_assets_are_displayed_once(): void {
        $this->resetAfterTest();

        $sessions = new \local_proctorcore\local\session_repository();
        $session = $sessions->create_new([
            'companyid' => 0,
            'courseid' => 101,
            'cmid' => 102,
            'quizid' => 103,
            'attemptid' => 104,
            'userid' => 105,
        ]);
        $assets = new \local_proctorcore\local\asset_repository();
        foreach (['speech_detected', 'multiple_faces'] as $index => $reason) {
            $assets->create((int) $session->id, 0, $assets::TYPE_VIDEO_CLIP, [
                'externalid' => 'clip-' . $index,
                'checksum' => hash('sha256', 'identical-video'),
                'mime' => 'video/mp4',
                'filesize' => 1024,
                'metadata' => [
                    'reason' => $reason,
                    'serverAssetType' => 'video_clip',
                ],
            ]);
        }

        $grouped = (new \local_proctorcore\local\report_service())
            ->get_report_assets((int) $session->id);

        $this->assertCount(1, $grouped['videos']);
        $this->assertSame(2, $grouped['videos'][0]->duplicatecount);
        $this->assertStringContainsString('Consolidated violations', $grouped['videos'][0]->displayname);
    }
}
