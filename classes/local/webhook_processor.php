<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/**
<<<<<<< HEAD
 * Validates, deduplicates, stores, and applies inbound Server B webhook events.
 */
final class webhook_processor {
=======
 * Validates, deduplicates, stores, and applies inbound Server B webhooks.
 *
 * Supported Section 1.1 events:
 * - asset.captured
 * - session.completed
 * - session.failed
 *
 * @package local_proctorcore
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class webhook_processor {
    /** Webhook inbox table. */
    private const TABLE = 'local_proctorcore_webhooks';

    /** Supported event types. */
    private const SUPPORTED_EVENTS = ['asset.captured', 'session.completed', 'session.failed'];

    /** @var session_repository */
    private $sessions;

    /** @var company_config_repository */
    private $configs;

    /** @var audit_logger */
    private $audit;

    /** @var retention_policy */
    private $retention;

    /** @var asset_repository */
    private $assets;

    /**
     * Constructor.
     *
     * @param session_repository|null $sessions Optional dependency.
     * @param company_config_repository|null $configs Optional dependency.
     * @param audit_logger|null $audit Optional dependency.
     * @param retention_policy|null $retention Optional dependency.
     * @param asset_repository|null $assets Optional dependency.
     */
    public function __construct(
        ?session_repository $sessions = null,
        ?company_config_repository $configs = null,
        ?audit_logger $audit = null,
        ?retention_policy $retention = null,
        ?asset_repository $assets = null
    ) {
        $this->sessions = $sessions ?? new session_repository();
        $this->configs = $configs ?? new company_config_repository();
        $this->audit = $audit ?? new audit_logger();
        $this->retention = $retention ?? new retention_policy($this->configs);
        $this->assets = $assets ?? new asset_repository($this->retention);
    }

    /**
     * Processes one raw signed webhook from Server B.
     *
     * @param string $rawpayload Exact JSON body received over HTTP.
     * @param string $signature HMAC signature from X-ProctorCore-Signature.
     * @return array Safe response for Server B.
     */
    public function process(string $rawpayload, string $signature): array {
        global $DB;

        $event = $this->decode_payload($rawpayload);
        $this->validate_event($event);

        $serversessionid = (string) $event['sessionId'];
        $moodlesessionid = isset($event['moodleSessionId']) ? (int) $event['moodleSessionId'] : null;
        $session = $this->sessions->find_for_webhook($serversessionid, $moodlesessionid);
        if (!$session) {
            throw new \moodle_exception('error:unknownwebhooksession', 'local_proctorcore');
        }

        $this->validate_event_ownership($event, $session);

        $config = $this->configs->get_effective_config((int) $session->companyid);
        $secret = (string) $config->webhooksecret;
        if ($secret === '') {
            throw new \moodle_exception('error:webhooksecretnotconfigured', 'local_proctorcore');
        }
        $this->validate_signature($rawpayload, $signature, $secret);

        $eventid = (string) $event['eventId'];
        $eventtype = (string) $event['eventType'];
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_proctorcore_webhook');
        $lock = $lockfactory->get_lock('event:' . hash('sha256', $eventid), 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }

        try {
            $existing = $this->get_existing_event($eventid);
            if ($existing && (int) $existing->sessionid !== (int) $session->id) {
                throw new \moodle_exception('error:webhookeventconflict', 'local_proctorcore');
            }
            if ($existing && (string) $existing->status === 'processed') {
                return [
                    'accepted' => true,
                    'status' => 'duplicate',
                    'eventid' => $eventid,
                    'sessionid' => (int) $session->id,
                ];
            }

            $webhookid = $existing
                ? $this->prepare_retry((int) $existing->id, $event, $rawpayload, $signature)
                : $this->store_received_event($session, $event, $rawpayload, $signature);
            $updatedsession = $session;
            $applied = [];

            try {
                $transaction = $DB->start_delegated_transaction();
                try {
                    if ($eventtype === 'asset.captured') {
                        $applied = $this->apply_asset_event($session, $event);
                        $updatedsession = $this->sessions->get_by_id((int) $session->id);
                    } else {
                        $updatedsession = $this->apply_final_event($session, $event);
                        $this->assets->apply_session_retention(
                            (int) $updatedsession->id,
                            (int) $updatedsession->reportexpiresat,
                            (int) $updatedsession->videoexpiresat
                        );
                    }

                    $this->mark_processed($webhookid);
                    $this->write_audit($event, $updatedsession, $webhookid, $applied);
                    $transaction->allow_commit();
                } catch (\Throwable $exception) {
                    $transaction->rollback($exception);
                }
            } catch (\Throwable $exception) {
                $this->mark_failed($webhookid, $exception);
                throw $exception;
            }

            if ($eventtype !== 'asset.captured') {
                $this->trigger_result_event($updatedsession, $eventid);
            }

            return [
                'accepted' => true,
                'status' => 'processed',
                'eventid' => $eventid,
                'sessionid' => (int) $updatedsession->id,
                'assetid' => isset($applied['asset']) ? (int) $applied['asset']->id : null,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Validates tenant/attempt/user references against the local session.
     *
     * @param array $event Event.
     * @param \stdClass $session Session.
     * @return void
     */
    private function validate_event_ownership(array $event, \stdClass $session): void {
        if (isset($event['companyId']) && (int) $event['companyId'] !== (int) $session->companyid) {
            throw new \moodle_exception('error:webhookcompanymismatch', 'local_proctorcore');
        }
        if (isset($event['attemptId']) && (int) $event['attemptId'] !== (int) $session->attemptid) {
            throw new \moodle_exception('error:webhookattemptmismatch', 'local_proctorcore');
        }
        if (isset($event['userId']) && (int) $event['userId'] !== (int) $session->userid) {
            throw new \moodle_exception('error:webhookusermismatch', 'local_proctorcore');
        }
    }

    /** @return \stdClass|null */
    private function get_existing_event(string $eventid): ?\stdClass {
        global $DB;
        $records = $DB->get_records(self::TABLE, ['eventid' => $eventid], 'id ASC', '*', 0, 1);
        if (!$records) {
            return null;
        }
        $record = reset($records);
        return $record ?: null;
    }

    /** @return array */
    private function decode_payload(string $rawpayload): array {
        if (trim($rawpayload) === '') {
            throw new \moodle_exception('error:emptywebhookpayload', 'local_proctorcore');
        }
        $event = json_decode($rawpayload, true);
        if (!is_array($event) || json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('error:invalidwebhookjson', 'local_proctorcore');
        }
        return $event;
    }

    /**
     * Validates common fields and event-specific payloads.
     *
     * @param array $event Event.
     * @return void
     */
    private function validate_event(array $event): void {
        foreach (['eventId', 'eventType', 'sessionId'] as $required) {
            if (!array_key_exists($required, $event) || trim((string) $event[$required]) === '') {
                throw new \moodle_exception('error:webhookfieldmissing', 'local_proctorcore', '', $required);
            }
        }

        if (!preg_match('/^[A-Za-z0-9_-]{3,128}$/', (string) $event['eventId'])) {
            throw new \moodle_exception('error:invalidwebhookeventid', 'local_proctorcore');
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', (string) $event['eventType'])) {
            throw new \moodle_exception('error:invalidwebhookeventtype', 'local_proctorcore');
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]{3,128}$/', (string) $event['sessionId'])) {
            throw new \moodle_exception('error:invalidwebhooksessionid', 'local_proctorcore');
        }
        if (!in_array((string) $event['eventType'], self::SUPPORTED_EVENTS, true)) {
            throw new \moodle_exception('error:unsupportedwebhookevent', 'local_proctorcore');
        }

        if ((string) $event['eventType'] === 'asset.captured') {
            if (!isset($event['asset']) || !is_array($event['asset'])) {
                throw new \moodle_exception('error:webhookfieldmissing', 'local_proctorcore', '', 'asset');
            }
            foreach (['assetId', 'type'] as $required) {
                if (empty($event['asset'][$required])) {
                    throw new \moodle_exception('error:webhookfieldmissing', 'local_proctorcore', '', 'asset.' . $required);
                }
            }
            return;
        }

        if (!array_key_exists('result', $event) || trim((string) $event['result']) === '') {
            throw new \moodle_exception('error:webhookfieldmissing', 'local_proctorcore', '', 'result');
        }
        $result = strtolower(trim((string) $event['result']));
        if (!in_array($result, ['passed', 'failed'], true)) {
            throw new \moodle_exception('error:invalidresult', 'local_proctorcore');
        }
    }

    /**
     * Validates the Server B HMAC-SHA256 signature.
     *
     * @param string $rawpayload Raw JSON.
     * @param string $signature Signature header.
     * @param string $secret Shared secret.
     * @return void
     */
    private function validate_signature(string $rawpayload, string $signature, string $secret): void {
        $received = trim($signature);
        if (stripos($received, 'sha256=') === 0) {
            $received = substr($received, 7);
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $received)) {
            throw new \moodle_exception('error:invalidwebhooksignature', 'local_proctorcore');
        }
        $expected = hash_hmac('sha256', $rawpayload, $secret);
        if (!hash_equals($expected, strtolower($received))) {
            throw new \moodle_exception('error:invalidwebhooksignature', 'local_proctorcore');
        }
    }

    /** @return int */
    private function store_received_event(
        \stdClass $session,
        array $event,
        string $rawpayload,
        string $signature
    ): int {
        global $DB;
        return (int) $DB->insert_record(self::TABLE, (object) [
            'companyid' => (int) $session->companyid,
            'sessionid' => (int) $session->id,
            'server_sessionid' => (string) $session->server_sessionid,
            'eventid' => (string) $event['eventId'],
            'eventtype' => (string) $event['eventType'],
            'signaturehash' => hash('sha256', $signature),
            'payload' => $rawpayload,
            'status' => 'processing',
            'attempts' => 1,
            'lasterror' => null,
            'receivedat' => time(),
            'processedat' => null,
        ]);
    }


    /**
     * Reopens a failed/stale webhook inbox row for an idempotent retry.
     *
     * @param int $webhookid Existing inbox id.
     * @param array $event Validated event.
     * @param string $rawpayload Exact JSON body.
     * @param string $signature Signature header.
     * @return int
     */
    private function prepare_retry(
        int $webhookid,
        array $event,
        string $rawpayload,
        string $signature
    ): int {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['id' => $webhookid], 'id,attempts', MUST_EXIST);
        $DB->update_record(self::TABLE, (object) [
            'id' => $webhookid,
            'eventtype' => (string) $event['eventType'],
            'signaturehash' => hash('sha256', $signature),
            'payload' => $rawpayload,
            'status' => 'processing',
            'attempts' => (int) $record->attempts + 1,
            'lasterror' => null,
            'processedat' => null,
        ]);
        return $webhookid;
    }

    /**
     * Registers a Server B media asset idempotently.
     *
     * @param \stdClass $session Session.
     * @param array $event Event.
     * @return array
     */
    private function apply_asset_event(\stdClass $session, array $event): array {
        $asset = $event['asset'];
        $externalid = clean_param((string) $asset['assetId'], PARAM_TEXT);
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_proctorcore_asset');
        $lockkey = hash('sha256', (int) $session->companyid . ':' . (int) $session->id . ':' . $externalid);
        $lock = $lockfactory->get_lock('asset:' . $lockkey, 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }

        try {
            $existing = $this->assets->get_by_external_id(
                $externalid,
                (int) $session->companyid,
                (int) $session->id
            );
            if ($existing) {
                return ['asset' => $existing, 'duplicateAsset' => true];
            }

            $assettype = $this->normalise_asset_type(
                (string) $asset['type'],
                (string) ($asset['reason'] ?? ($asset['metadata']['reason'] ?? ''))
            );
            $availableat = $this->parse_timestamp(
                $asset['availableAt'] ?? $asset['capturedAt'] ?? null
            ) ?? time();
            $violationid = isset($asset['violationId']) ? (int) $asset['violationId'] : null;

            $record = $this->assets->create(
                (int) $session->id,
                (int) $session->companyid,
                $assettype,
                [
                    'violationid' => $violationid,
                    'storage' => 'server_b',
                    'externalid' => $externalid,
                    // Do not persist a permanent/public media URL. Authorised views
                    // should ask Server B for a short-lived signed retrieval URL.
                    'url' => null,
                    'checksum' => isset($asset['checksum'])
                        ? clean_param((string) $asset['checksum'], PARAM_ALPHANUM)
                        : null,
                    'mime' => isset($asset['mimeType'])
                        ? clean_param((string) $asset['mimeType'], PARAM_TEXT)
                        : null,
                    'filesize' => isset($asset['sizeBytes'])
                        ? max(0, (int) $asset['sizeBytes'])
                        : null,
                    'availableat' => $availableat,
                    'expiresat' => null,
                    'metadata' => [
                        'capturedAt' => $asset['capturedAt'] ?? null,
                        'availableAt' => $asset['availableAt'] ?? null,
                        'reason' => $asset['reason'] ?? ($asset['metadata']['reason'] ?? null),
                        'recordingSegment' => $asset['recordingSegment']
                            ?? ($asset['metadata']['recordingSegment'] ?? null),
                        'serverMetadata' => $asset['metadata'] ?? null,
                    ],
                ]
            );

            if (in_array(
                $assettype,
                [asset_repository::TYPE_SNAPSHOT, asset_repository::TYPE_IDENTITY_PHOTO],
                true
            )) {
                $this->sessions->increment_snapshot_count((int) $session->id);
            }

            return ['asset' => $record, 'duplicateAsset' => false];
        } finally {
            $lock->release();
        }
    }

    /**
     * Applies a final Passed/Failed result and retention dates.
     *
     * @param \stdClass $session Session.
     * @param array $event Event.
     * @return \stdClass
     */
    private function apply_final_event(\stdClass $session, array $event): \stdClass {
        $result = strtolower(trim((string) $event['result']));
        $eventtype = (string) $event['eventType'];
        $status = $eventtype === 'session.failed' ? 'failed' : 'completed';
        $completedat = $this->parse_timestamp($event['completedAt'] ?? null) ?? time();
        $companyid = (int) $session->companyid;
        $appealuntil = $this->retention->compute_appeal_deadline($companyid, $completedat);
        $reportexpiresat = $this->retention->compute_report_expiry($companyid, $completedat);
        $videoexpiresat = $this->retention->compute_video_expiry($companyid, $completedat);
        $reason = (string) ($event['reasonCode'] ?? $event['reason'] ?? ('server_' . $result));

        $updated = $this->sessions->apply_final_result(
            (int) $session->id,
            $result,
            $status,
            $reason,
            $completedat,
            $appealuntil,
            $reportexpiresat,
            $videoexpiresat,
            $event
        );

        // Report generation must not make a valid Passed/Failed webhook fail.
        // A scheduled task retries any report that could not be generated here.
        try {
            (new report_pdf_service())->generate_and_store(
                (int) $updated->id,
                null,
                'final_webhook'
            );
        } catch (\Throwable $exception) {
            debugging(
                'ProctorCore final report generation failed for session ' . (int) $updated->id
                . ': ' . $exception->getMessage(),
                DEBUG_DEVELOPER
            );
            try {
                $this->audit->log(
                    'report.generation_failed',
                    (int) $updated->companyid,
                    (int) $updated->id,
                    (int) $updated->userid,
                    [
                        'eventId' => (string) ($event['eventId'] ?? ''),
                        'error' => clean_param($exception->getMessage(), PARAM_TEXT),
                    ],
                    null,
                    'session',
                    (int) $updated->id
                );
            } catch (\Throwable $auditexception) {
                debugging('ProctorCore could not audit report failure: ' . $auditexception->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return $updated;
    }

    /**
     * Maps compatible Server B names to local asset constants.
     *
     * @param string $type External type.
     * @param string $reason Capture reason.
     * @return string
     */
    private function normalise_asset_type(string $type, string $reason): string {
        $type = strtolower(trim($type));
        $reason = strtolower(trim($reason));

        if (in_array($type, ['video', 'video_clip', 'recording', 'recording_segment', 'clip'], true)) {
            return asset_repository::TYPE_VIDEO_CLIP;
        }
        if (in_array($type, ['report', 'pdf_report', 'pdf'], true)) {
            return asset_repository::TYPE_REPORT;
        }
        if (in_array($type, ['identity_photo', 'identity', 'id_photo'], true)
                || ($type === 'snapshot' && $reason === 'identity_verification')) {
            return asset_repository::TYPE_IDENTITY_PHOTO;
        }
        if (in_array($type, ['snapshot', 'photo', 'image'], true)) {
            return asset_repository::TYPE_SNAPSHOT;
        }
        if ($type === 'room_scan') {
            return asset_repository::TYPE_ROOM_SCAN;
        }
        if (in_array($type, ['violation_act', 'incident_act'], true)) {
            return asset_repository::TYPE_VIOLATION_ACT;
        }
        throw new \moodle_exception('error:invalidassettype', 'local_proctorcore', '', $type);
    }

    /** @return int|null */
    private function parse_timestamp($value): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || ctype_digit((string) $value)) {
            return (int) $value;
        }
        try {
            return (new \DateTimeImmutable((string) $value))->getTimestamp();
        } catch (\Throwable $exception) {
            throw new \moodle_exception('error:invalidwebhooktimestamp', 'local_proctorcore');
        }
    }

    /** @return void */
    private function mark_processed(int $webhookid): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $webhookid,
            'status' => 'processed',
            'lasterror' => null,
            'processedat' => time(),
        ]);
    }

    /** @return void */
    private function mark_failed(int $webhookid, \Throwable $exception): void {
        global $DB;
        $DB->update_record(self::TABLE, (object) [
            'id' => $webhookid,
            'status' => 'failed',
            'lasterror' => clean_param($exception->getMessage(), PARAM_TEXT),
            'processedat' => time(),
        ]);
    }

    /**
     * Writes a webhook audit entry.
     *
     * @param array $event Event.
     * @param \stdClass $session Session.
     * @param int $webhookid Inbox id.
     * @param array $applied Applied details.
     * @return void
     */
    private function write_audit(array $event, \stdClass $session, int $webhookid, array $applied): void {
        $eventtype = (string) $event['eventType'];
        $details = [
            'eventId' => (string) $event['eventId'],
            'eventType' => $eventtype,
            'serverSessionId' => (string) $session->server_sessionid,
            'attemptId' => (int) $session->attemptid,
        ];
        $action = 'integration.result_received';
        $targettype = 'webhook';
        $targetid = $webhookid;

        if ($eventtype === 'asset.captured' && isset($applied['asset'])) {
            $action = 'integration.asset_received';
            $targettype = 'asset';
            $targetid = (int) $applied['asset']->id;
            $details['assetId'] = (int) $applied['asset']->id;
            $details['externalAssetId'] = (string) $applied['asset']->externalid;
            $details['assetType'] = (string) $applied['asset']->assettype;
            $details['duplicateAsset'] = !empty($applied['duplicateAsset']);
        } else {
            $details['result'] = (string) $session->result;
            $details['status'] = (string) $session->status;
        }

        $this->audit->log(
            $action,
            (int) $session->companyid,
            (int) $session->id,
            (int) $session->userid,
            $details,
            null,
            $targettype,
            $targetid
        );
    }

    /**
     * Triggers a standard Moodle result event for downstream protocol logic.
     *
     * @param \stdClass $session Updated session.
     * @param string $eventid Server event id.
     * @return void
     */
    private function trigger_result_event(\stdClass $session, string $eventid): void {
        $context = \context_module::instance((int) $session->cmid);
        $event = \local_proctorcore\event\proctoring_result_received::create([
            'objectid' => (int) $session->id,
            'context' => $context,
            'relateduserid' => (int) $session->userid,
            'other' => [
                'server_sessionid' => (string) $session->server_sessionid,
                'server_eventid' => $eventid,
                'attemptid' => (int) $session->attemptid,
                'quizid' => (int) $session->quizid,
                'companyid' => (int) $session->companyid,
                'result' => (string) $session->result,
                'status' => (string) $session->status,
            ],
        ]);
        $event->trigger();
    }
>>>>>>> origin/danial
}
