<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\task;

defined('MOODLE_INTERNAL') || die();

/** Retries biometric reference deletions that previously failed remotely. */
final class retry_reference_deletions_task extends \core\task\scheduled_task {
    /** @return string */
    public function get_name(): string {
        return get_string('task:retryreferencedeletions', 'local_proctorcore');
    }

    /** @return void */
    public function execute(): void {
        $repository = new \local_proctorcore\local\face_enrollment_repository();
        foreach ($repository->get_deletion_candidates() as $record) {
            try {
                $response = (new \local_proctorcore\local\server_client((int) $record->companyid))
                    ->reset_face_reference((int) $record->userid, (string) $record->resetreason);
                $repository->mark_deleted((int) $record->userid, $record->resetby ?: null,
                    (string) $record->resetreason);
                (new \local_proctorcore\local\audit_logger())->log(
                    'identity.reference_erased_retry', (int) $record->companyid, null, (int) $record->userid,
                    ['deletionReceipt' => $response['receipt'] ?? $response],
                    $record->resetby ?: null, 'user', (int) $record->userid
                );
            } catch (\Throwable $exception) {
                $repository->mark_deletion_failed((int) $record->userid, $exception->getMessage());
                mtrace('ProctorCore reference deletion retry failed for user ' . (int) $record->userid . ': '
                    . $exception->getMessage());
            }
        }
    }
}
