# local_proctorcore

Main Moodle-side control centre and official record keeper for the SENTAL proctoring system.

## File map

- `version.php` - Declares the Moodle plugin component, version, Moodle requirement, and release status.
- `index.php` - Placeholder for the ProctorCore landing/dashboard route.
- `settings.php` - Holds global admin settings such as Server B URL, webhook secret, and retention defaults.
- `lib.php` - Exposes shared helper functions for `quizaccess_proctorgate` and other Moodle-side plugins.
- `webhook.php` - Placeholder for a direct signed Server B webhook endpoint if Moodle web services are not used.
- `reports.php` - Placeholder for company-scoped report lists and session detail pages.
- `appeal.php` - Placeholder for student appeal submission and appeal review entry points.
- `db/install.xml` - Defines the full database schema for sessions, tenant settings, violations, assets, appeals, webhooks, participant fields, checks, acknowledgements, and audit logs.
- `db/upgrade.php` - Placeholder for future schema and data upgrade steps.
- `db/access.php` - Defines Moodle capabilities for own reports, company reports, all-company reports, exports, appeals, audit logs, and plugin management.
- `db/tasks.php` - Registers the scheduled retention cleanup task.
- `db/services.php` - Registers the Server B webhook web-service function.
- `lang/en/local_proctorcore.php` - Contains English language strings for the plugin, settings, capabilities, and task names.
- `classes/local/session_repository.php` - Home for session create/read/update logic and official attempt-to-proctoring mapping.
- `classes/local/gate_service.php` - Home for quiz admission decisions consumed by `quizaccess_proctorgate`.
- `classes/local/tenant_resolver.php` - Home for IOMAD company resolution and tenant-scope checks.
- `classes/local/retention_policy.php` - Home for retention and appeal-hold date calculations.
- `classes/local/webhook_processor.php` - Home for webhook signature validation, deduplication, persistence, and session updates.
- `classes/local/report_service.php` - Home for assembling company-scoped report data from sessions, violations, assets, and quiz attempts.
- `classes/local/appeal_service.php` - Home for appeal submission, review state changes, and evidence retention holds.
- `classes/local/asset_repository.php` - Home for report, video, snapshot, room scan, ID photo, and violation-act references.
- `classes/local/audit_logger.php` - Home for append-only administrator, coordinator, proctor, and integration audit events.
- `classes/task/cleanup_retention_task.php` - Scheduled task that will clear expired evidence links and request external deletion.
- `classes/external/webhook_receiver.php` - Web-service receiver for signed Server B lifecycle, result, asset, and violation events.
- `classes/privacy/provider.php` - Moodle privacy metadata declaration for stored proctoring personal data.
- `classes/form/appeal_form.php` - Placeholder for the Moodle form used by students to file appeals.
- `classes/form/participant_fields_form.php` - Placeholder for configuring custom participant fields per company.
- `classes/output/report_renderer.php` - Placeholder for preparing report summary and evidence display data.
- `templates/report_summary.mustache` - Placeholder Mustache template for a proctoring report summary block.
- `amd/src/proctorcore.js` - Placeholder AMD JavaScript module for small page interactions.
- `tests/session_repository_test.php` - Placeholder PHPUnit test file for session record persistence.

## Schema overview

- `local_proctorcore_companycfg` - Per-company integration, retention, language, instruction, and feature settings.
- `local_proctorcore_quizcfg` - Per-quiz proctoring enablement and gate requirements.
- `local_proctorcore_fields` - Configurable participant data fields such as IIN, department, or course-specific identifiers.
- `local_proctorcore_sessions` - Official proctoring session record linked to Moodle course, quiz, attempt, user, and Server B session ids.
- `local_proctorcore_fieldvals` - Captured participant field values for each proctoring session.
- `local_proctorcore_checks` - Equipment, browser, speed, camera, microphone, and lighting check results.
- `local_proctorcore_rulesack` - Logged acknowledgement of exam rules before admission to the quiz.
- `local_proctorcore_violations` - AI/server and manually flagged violations with timestamps, severity, source, and metadata.
- `local_proctorcore_assets` - References to PDFs, videos, clips, snapshots, ID images, room scans, and violation acts with retention status.
- `local_proctorcore_webhooks` - Raw inbound Server B events plus processing status for idempotency and troubleshooting.
- `local_proctorcore_appeals` - Appeal requests, reasons, reviewer decisions, and evidence-hold state linkage.
- `local_proctorcore_audit` - Append-only administrator, coordinator, proctor, and integration action log.
