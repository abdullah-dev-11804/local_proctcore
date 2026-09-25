<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Localises stored machine values for reports and review screens.
 *
 * @package local_proctorcore
 */
final class localised_value {
    /** @var array<string, string> Stored violation type to language key. */
    private const VIOLATION_KEYS = [
        'background_noise' => 'violation:background_noise',
        'speech_detected' => 'violation:speech_detected',
        'second_voice_detected' => 'violation:second_voice_detected',
        'possible_prompting' => 'violation:possible_prompting',
        'no_face' => 'violation:no_face',
        'multiple_faces' => 'violation:multiple_faces',
        'looking_away' => 'violation:looking_away',
        'spoof_detected' => 'violation:spoof_detected',
        'different_person' => 'violation:differentperson',
        'differentperson' => 'violation:differentperson',
        'tab_hidden' => 'violation:tabhidden',
        'window_blur' => 'violation:windowblur',
        'camera_ended' => 'violation:cameraended',
        'microphone_ended' => 'violation:microphoneended',
        'camera_blocked' => 'violation:camerablocked',
        'screen_share_not_started' => 'violation:screensharenotstarted',
        'screen_share_unsupported' => 'violation:screenshareunsupported',
        'screen_share_denied' => 'violation:screensharedenied',
        'screen_share_incomplete' => 'violation:screenshareincomplete',
        'screen_share_unavailable' => 'violation:screenshareunavailable',
        'screen_share_ended' => 'violation:screenshareended',
        'identity_mismatch' => 'violation:identity_mismatch',
    ];

    /** Localises a known stored status, result, policy, reason, or asset type. */
    public static function value(string $value, ?string $lang = null): string {
        $normal = self::normalise($value);
        if ($normal === '') {
            return '—';
        }
        $key = 'value:' . $normal;
        if (get_string_manager()->string_exists($key, 'local_proctorcore')) {
            return get_string($key, 'local_proctorcore', null, $lang);
        }
        return get_string('report:notavailable', 'local_proctorcore', null, $lang);
    }

    /** Localises a violation type, with an optional stored-description fallback. */
    public static function violation(string $type, string $fallback = '', ?string $lang = null): string {
        $normal = self::normalise($type);
        $key = self::VIOLATION_KEYS[$normal] ?? '';
        if ($key !== '' && get_string_manager()->string_exists($key, 'local_proctorcore')) {
            return get_string($key, 'local_proctorcore', null, $lang);
        }
        return $fallback !== '' ? $fallback : self::value($normal, $lang);
    }

    /** Localises one of the supported appeal reasons. */
    public static function appeal_reason(string $reason, ?string $lang = null): string {
        $keys = [
            'technical_failure' => 'appeal:technicalfailure',
            'identification_error' => 'appeal:identificationerror',
            'other' => 'appeal:other',
        ];
        $normal = self::normalise($reason);
        return isset($keys[$normal])
            ? get_string($keys[$normal], 'local_proctorcore', null, $lang)
            : self::value($normal, $lang);
    }

    /** Normalises a stored identifier for a language-key lookup. */
    private static function normalise(string $value): string {
        $value = strtolower(trim($value));
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $value), '_');
    }
}
