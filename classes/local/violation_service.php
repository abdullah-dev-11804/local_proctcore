<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Section 1.3 behaviour-monitoring service.
 *
 * ML frames are intentionally sampled rather than streamed through Moodle.
 * Browser events are recorded immediately; face events use configured duration
 * thresholds and cooldowns to reduce false positives.
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class violation_service {
    /**
     * Analyses one camera frame and confirms thresholded violations.
     *
     * @param int $sessionid Session id.
     * @param int $userid Current user id.
     * @param string $framedata Data URL/base64 JPEG.
     * @return array
     */
    public function analyse_frame(int $sessionid, int $userid, string $framedata): array {
        $sessions = new session_repository();
        $session = $sessions->get_by_id($sessionid);
        $this->require_active_owner($session, $userid);

        $config = (new company_config_repository())->get_effective_config((int) $session->companyid);
        if (empty($config->monitoringenabled)) {
            return ['ok' => true, 'monitoringEnabled' => false, 'violations' => []];
        }

        $frame = $this->decode_image($framedata);
        $metadata = $this->decode_metadata($session->servermetadata);
        $monitor = is_array($metadata['monitor'] ?? null) ? $metadata['monitor'] : [];
        $now = time();
        $lastidentity = (int) ($monitor['lastIdentityRecheckAt'] ?? 0);
        $includereference = $lastidentity <= 0
            || $now - $lastidentity >= (int) $config->identityrecheckseconds;
        $reference = null;
        if ($includereference) {
            $reference = (new identity_service())->get_profile_image_bytes((int) $session->userid);
        }

        $analysis = (new ml_client((int) $session->companyid))->analyse_frame(
            $frame,
            $reference,
            (string) ($session->server_sessionid ?: ('local-' . $sessionid))
        );

        $events = [];
        $monitor = $this->update_condition(
            $session,
            $monitor,
            'no_face',
            ((int) ($analysis['faceCount'] ?? 0)) === 0,
            (int) $config->nofaceseconds,
            3,
            $analysis,
            $events
        );
        $monitor = $this->update_condition(
            $session,
            $monitor,
            'multiple_faces',
            ((int) ($analysis['faceCount'] ?? 0)) > 1,
            (int) $config->multiplefaceseconds,
            5,
            $analysis,
            $events
        );
        $monitor = $this->update_condition(
            $session,
            $monitor,
            'looking_away',
            !empty($analysis['lookingAway']) && ((int) ($analysis['faceCount'] ?? 0)) === 1,
            (int) $config->lookawayseconds,
            2,
            $analysis,
            $events
        );

        if ($includereference) {
            $monitor['lastIdentityRecheckAt'] = $now;
            $identityresult = (string) ($analysis['identityResult'] ?? 'not_checked');
            if ($identityresult === 'not_matched') {
                $violation = $this->create_once(
                    $session,
                    'different_person',
                    5,
                    'identity_model',
                    (int) $config->violationcooldownseconds,
                    [
                        'occurredat' => $now,
                        'description' => get_string('violation:differentperson', 'local_proctorcore'),
                        'metadata' => $analysis,
                    ]
                );
                if ($violation) {
                    $events[] = $this->public_violation($violation);
                    (new session_repository())->update_check_statuses(
                        $sessionid,
                        (string) $session->techcheckstatus,
                        'failed',
                        ['monitor' => ['identityRecheck' => $analysis]]
                    );
                }
            }
        }

        $monitor['lastAnalysisAt'] = $now;
        $monitor['lastAnalysis'] = $analysis;
        $sessions->merge_server_metadata($sessionid, ['monitor' => $monitor]);

        return [
            'ok' => true,
            'monitoringEnabled' => true,
            'analysis' => [
                'faceCount' => (int) ($analysis['faceCount'] ?? 0),
                'lookingAway' => !empty($analysis['lookingAway']),
                'yaw' => isset($analysis['yaw']) ? (float) $analysis['yaw'] : null,
                'identityResult' => (string) ($analysis['identityResult'] ?? 'not_checked'),
                'similarityScore' => isset($analysis['similarityScore'])
                    ? (float) $analysis['similarityScore']
                    : null,
            ],
            'violations' => $events,
        ];
    }

    /**
     * Records a deterministic browser/media event.
     *
     * @param int $sessionid Session id.
     * @param int $userid User id.
     * @param string $eventtype Event type.
     * @param array $metadata Metadata.
     * @return array
     */
    public function record_browser_event(
        int $sessionid,
        int $userid,
        string $eventtype,
        array $metadata = []
    ): array {
        $session = (new session_repository())->get_by_id($sessionid);
        $this->require_active_owner($session, $userid);

        $map = [
            'tab_hidden' => [3, get_string('violation:tabhidden', 'local_proctorcore')],
            'window_blur' => [2, get_string('violation:windowblur', 'local_proctorcore')],
            'camera_ended' => [4, get_string('violation:cameraended', 'local_proctorcore')],
            'microphone_ended' => [4, get_string('violation:microphoneended', 'local_proctorcore')],
            'camera_blocked' => [3, get_string('violation:camerablocked', 'local_proctorcore')],
        ];
        if (!isset($map[$eventtype])) {
            throw new \moodle_exception('violation:invalidevent', 'local_proctorcore');
        }
        $config = (new company_config_repository())->get_effective_config((int) $session->companyid);
        $violation = $this->create_once(
            $session,
            $eventtype,
            (int) $map[$eventtype][0],
            'browser',
            (int) $config->violationcooldownseconds,
            [
                'description' => (string) $map[$eventtype][1],
                'metadata' => $metadata,
            ]
        );

        return [
            'ok' => true,
            'violation' => $violation ? $this->public_violation($violation) : null,
            'suppressed' => !$violation,
        ];
    }

    /**
     * @param \stdClass $session Session.
     * @param array $monitor State.
     * @param string $type Type.
     * @param bool $active Whether currently active.
     * @param int $thresholdseconds Confirmation threshold.
     * @param int $severity Severity.
     * @param array $analysis Analysis metadata.
     * @param array $events Output events.
     * @return array Updated state.
     */
    private function update_condition(
        \stdClass $session,
        array $monitor,
        string $type,
        bool $active,
        int $thresholdseconds,
        int $severity,
        array $analysis,
        array &$events
    ): array {
        $now = time();
        $conditions = is_array($monitor['conditions'] ?? null) ? $monitor['conditions'] : [];
        $state = is_array($conditions[$type] ?? null) ? $conditions[$type] : [];
        if (!$active) {
            $state = ['activeSince' => null, 'lastSeenAt' => $now];
            $conditions[$type] = $state;
            $monitor['conditions'] = $conditions;
            return $monitor;
        }

        $activefrom = (int) ($state['activeSince'] ?? 0);
        if ($activefrom <= 0) {
            $activefrom = $now;
        }
        $state['activeSince'] = $activefrom;
        $state['lastSeenAt'] = $now;
        $conditions[$type] = $state;
        $monitor['conditions'] = $conditions;

        if ($now - $activefrom < max(1, $thresholdseconds)) {
            return $monitor;
        }

        $config = (new company_config_repository())->get_effective_config((int) $session->companyid);
        $violation = $this->create_once(
            $session,
            $type,
            $severity,
            'ml_service',
            (int) $config->violationcooldownseconds,
            [
                'occurredat' => $activefrom,
                'durationms' => max(1, $now - $activefrom) * 1000,
                'description' => get_string('violation:' . $type, 'local_proctorcore'),
                'metadata' => $analysis,
            ]
        );
        if ($violation) {
            $events[] = $this->public_violation($violation);
            $monitor['conditions'][$type]['activeSince'] = $now;
        }
        return $monitor;
    }

    /**
     * @param \stdClass $session Session.
     * @param string $type Type.
     * @param int $severity Severity.
     * @param string $source Source.
     * @param int $cooldown Cooldown seconds.
     * @param array $data Data.
     * @return \stdClass|null
     */
    private function create_once(
        \stdClass $session,
        string $type,
        int $severity,
        string $source,
        int $cooldown,
        array $data
    ): ?\stdClass {
        $repository = new violation_repository();
        if ($repository->get_recent((int) $session->id, $type, time() - max(1, $cooldown))) {
            return null;
        }
        return $repository->create((int) $session->id, $type, $severity, $source, $data);
    }

    /** @return array */
    private function public_violation(\stdClass $violation): array {
        return [
            'id' => (int) $violation->id,
            'type' => (string) $violation->type,
            'severity' => (int) $violation->severity,
            'occurredAt' => (int) $violation->occurredat,
        ];
    }

    /**
     * @param \stdClass $session Session.
     * @param int $userid User id.
     * @return void
     */
    private function require_active_owner(\stdClass $session, int $userid): void {
        if ((int) $session->userid !== $userid) {
            throw new \moodle_exception('error:sessionowner', 'local_proctorcore');
        }
        if (!in_array((string) $session->status, ['active', 'interrupted'], true)) {
            throw new \moodle_exception('error:sessionclosed', 'local_proctorcore');
        }
    }

    /** @return string */
    private function decode_image(string $data): string {
        $clean = trim($data);
        if (strpos($clean, ',') !== false) {
            [, $clean] = explode(',', $clean, 2);
        }
        $bytes = base64_decode($clean, true);
        if ($bytes === false || strlen($bytes) < 256 || strlen($bytes) > 5 * 1024 * 1024) {
            throw new \moodle_exception('identity:invalidimage', 'local_proctorcore');
        }
        return $bytes;
    }

    /** @return array */
    private function decode_metadata(?string $json): array {
        if (!$json) {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
