# Section 4.2 using the existing ProctorCore database schema

No changes to `db/install.xml` or `db/upgrade.php` are required.

The existing schema already provides all fields needed by the signed result webhook:

- `local_proctorcore_sessions`: `status`, `result`, `endedat`, `appealuntil`, `reportexpiresat`, `videoexpiresat`, `closedreason`, `servermetadata`.
- `local_proctorcore_webhooks`: event ID, event type, signature hash, payload, status, attempts, errors, received and processed timestamps.
- `local_proctorcore_audit`: append-only integration audit records.

The existing `eventid` index is non-unique. `webhook_processor.php` therefore uses Moodle's Lock API to prevent two concurrent Server B retries from processing the same event at the same time. This keeps the original database design unchanged.

Replace only:

`local/proctorcore/classes/local/webhook_processor.php`

Then purge Moodle caches. A database upgrade is not required for this correction.
