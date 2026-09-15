<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/** Applies the administrator-defined violation scoring and outcome policy. */
final class violation_scoring_service {
    /** Default points preserve the former severity multiplied by ten calculation. */
    private const POINTS = [
        'no_face' => ['riskpointsnoface', 30],
        'looking_away' => ['riskpointslookingaway', 20],
        'multiple_faces' => ['riskpointsmultiplefaces', 50],
        'tab_hidden' => ['riskpointstabhidden', 30],
        'window_blur' => ['riskpointswindowblur', 20],
        'spoof_detected' => ['riskpointsspoof', 50],
        'camera_ended' => ['riskpointscameraended', 40],
        'microphone_ended' => ['riskpointsmicrophoneended', 40],
        'camera_blocked' => ['riskpointscamerablocked', 30],
        'speech_detected' => ['riskpointsspeechdetected', 30],
    ];

    /** Returns the effective global scoring policy. */
    public function get_policy(): array {
        $points = [];
        foreach (self::POINTS as $type => [$configkey, $default]) {
            $points[$type] = $this->config_int($configkey, $default, 0, 100);
        }
        $points['other'] = $this->config_int('riskpointsother', 10, 0, 100);
        $review = $this->config_int('riskreviewthreshold', 40, 1, 99);
        $failure = $this->config_int('riskfailthreshold', 80, $review + 1, 100);
        return [
            'points' => $points,
            'reviewThreshold' => $review,
            'failureThreshold' => $failure,
        ];
    }

    /** Returns the points captured for one violation occurrence. */
    public function points_for(string $type): int {
        $policy = $this->get_policy();
        $type = clean_param($type, PARAM_ALPHANUMEXT);
        if (array_key_exists($type, $policy['points'])) {
            return (int) $policy['points'][$type];
        }
        return (int) $policy['points']['other'];
    }

    /** Classifies a cumulative score without changing a session. */
    public function evaluate(float $score): array {
        $policy = $this->get_policy();
        $score = min(100.0, max(0.0, $score));
        $decision = $score >= $policy['failureThreshold']
            ? 'failed'
            : ($score >= $policy['reviewThreshold'] ? 'manual_review' : 'passed');
        return $policy + ['score' => $score, 'decision' => $decision];
    }

    /** Applies current thresholds and stores the effective policy with the session. */
    public function apply_to_session(int $sessionid): array {
        global $DB;

        $factory = \core\lock\lock_config::get_lock_factory('local_proctorcore_violation_scoring');
        $lock = $factory->get_lock('session-' . $sessionid, 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout', 'error');
        }
        try {
            $session = $DB->get_record('local_proctorcore_sessions', ['id' => $sessionid], '*', MUST_EXIST);
            $violations = $DB->get_records(
                'local_proctorcore_violations',
                ['sessionid' => $sessionid],
                'id ASC',
                'id,type,metadata'
            );
            $score = 0;
            foreach ($violations as $violation) {
                $violationmetadata = json_decode((string) ($violation->metadata ?? ''), true);
                $violationmetadata = is_array($violationmetadata) ? $violationmetadata : [];
                $score += isset($violationmetadata['riskPoints'])
                    ? min(100, max(0, (int) $violationmetadata['riskPoints']))
                    : $this->points_for((string) $violation->type);
            }
            $evaluation = $this->evaluate(min(100, $score));
            $metadata = json_decode((string) ($session->servermetadata ?? ''), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $previousdecision = (string) ($metadata['violationScoring']['decision'] ?? '');
            $metadata['violationScoring'] = [
                'points' => $evaluation['points'],
                'reviewThreshold' => $evaluation['reviewThreshold'],
                'failureThreshold' => $evaluation['failureThreshold'],
                'score' => $evaluation['score'],
                'decision' => $evaluation['decision'],
                'evaluatedAt' => time(),
            ];

            $update = (object) [
                'id' => $sessionid,
                'risk_score' => $evaluation['score'],
                'violationcount' => count($violations),
                'servermetadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'timemodified' => time(),
            ];
            if ($evaluation['decision'] === 'manual_review' || $evaluation['decision'] === 'failed') {
                $update->reviewrequired = 1;
            }
            if ($evaluation['decision'] === 'failed') {
                $update->result = 'failed';
            }
            $DB->update_record('local_proctorcore_sessions', $update);

            if ($previousdecision !== $evaluation['decision']
                    && in_array($evaluation['decision'], ['manual_review', 'failed'], true)) {
                (new audit_logger())->log(
                    'session.risk_threshold_reached',
                    (int) $session->companyid,
                    $sessionid,
                    (int) $session->userid,
                    [
                        'score' => $evaluation['score'],
                        'decision' => $evaluation['decision'],
                        'reviewThreshold' => $evaluation['reviewThreshold'],
                        'failureThreshold' => $evaluation['failureThreshold'],
                    ],
                    null,
                    'session',
                    $sessionid
                );
            }
            return $evaluation;
        } finally {
            $lock->release();
        }
    }

    /** Reads and bounds one integer plugin setting. */
    private function config_int(string $name, int $default, int $minimum, int $maximum): int {
        $value = get_config('local_proctorcore', $name);
        $value = $value === false ? $default : (int) $value;
        return min($maximum, max($minimum, $value));
    }
}
