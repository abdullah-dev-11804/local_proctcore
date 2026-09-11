# local_proctorcore

Main Moodle-side control centre and official record keeper for the SENTAL proctoring system.

## File map

- `version.php` - Declares the Moodle plugin component, version, Moodle requirement, and release status.
- `index.php` - Placeholder for the ProctorCore landing/dashboard route.
- `settings.php` - Holds global admin settings such as Server B URL, webhook secret, and retention defaults.
- `lib.php` - Exposes shared helper functions for `quizaccess_proctorcore` and other Moodle-side plugins.
- `webhook.php` - Receives signed Proctoring Server webhooks and returns idempotent JSON acknowledgements.
- `reports.php` - Renders tenant-scoped report lists and detailed session evidence.
- `appeal.php` - Handles student appeal submission and authorised review decisions for one session.
- `appeals.php` - Lists tenant-scoped appeals awaiting authorised review.
- `consent.php` - Enforces personal acceptance of the current required document versions.
- `consent_documents.php` - Publishes and activates immutable multilingual consent-document versions.
- `consent_export.php` - Exports the append-only consent evidence log as CSV.
- `participant_fields.php` - Manages company-scoped multilingual participant fields and Moodle profile mappings.
- `reset_face.php` - Admin entry point for resetting a user's reusable Server B face reference.
- `cli/reset_face_reference.php` - CLI helper to reset a user's reusable face reference and force fresh enrollment.
- `cli/purge_test_data.php` - Safely previews or purges pre-production ProctorCore transactions while preserving configuration.
- `db/install.xml` - Defines the full database schema for sessions, face enrollment metadata, tenant settings, violations, assets, appeals, webhooks, participant fields, checks, acknowledgements, and audit logs.
- `db/upgrade.php` - Applies schema upgrades for installed Moodle sites.
- `db/access.php` - Defines Moodle capabilities for reports, exports, appeals, audit logs, plugin management, and face-reference reset.
- `db/events.php` - Handles face-reference deletion and course-completion evidence release.
- `db/tasks.php` - Registers retention, report, recovery, reference-deletion, and appeal-hold tasks.
- `db/messages.php` - Registers appeal submission and decision notifications.
- `db/services.php` - Registers the Server B webhook web-service function.
- `lang/en/local_proctorcore.php` - Contains English language strings for the plugin, settings, capabilities, and task names.
- `classes/local/session_repository.php` - Home for session create/read/update logic and official attempt-to-proctoring mapping.
- `classes/local/face_enrollment_repository.php` - Home for one-reference-per-user enrollment metadata and reset status.
- `classes/local/gate_service.php` - Home for quiz admission decisions consumed by `quizaccess_proctorcore`.
- `classes/local/tenant_resolver.php` - Home for IOMAD company resolution and tenant-scope checks.
- `classes/local/retention_policy.php` - Home for retention and appeal-hold date calculations.
- `classes/local/webhook_processor.php` - Home for webhook signature validation, deduplication, persistence, and session updates.
- `classes/local/report_service.php` - Home for assembling company-scoped report data from sessions, violations, assets, and quiz attempts.
- `classes/local/appeal_service.php` - Home for appeal submission, review state changes, and evidence retention holds.
- `classes/local/consent_service.php` - Owns versioned documents, renewed-consent checks, impersonation protection, and immutable acceptance logs.
- `classes/local/participant_field_service.php` - Owns participant field definitions, prefill, validation, reusable values, profile synchronization, and report snapshots.
- `classes/local/rules_service.php` - Records explicit acknowledgements against the exact configured exam-rules hash.
- `classes/local/asset_repository.php` - Home for report, video, snapshot, room scan, ID photo, and violation-act references.
- `classes/local/audit_logger.php` - Home for append-only administrator, coordinator, proctor, and integration audit events.
- `classes/observer.php` - Handles account-deletion face-reference cleanup and course-completion appeal release.
- `classes/task/cleanup_retention_task.php` - Deletes expired, non-held Moodle and Proctoring Server evidence with retry-safe audit records.
- `classes/external/webhook_receiver.php` - Web-service receiver for signed lifecycle, result, and asset events.
- `classes/privacy/provider.php` - Moodle privacy metadata declaration for stored proctoring personal data.
- `classes/form/appeal_form.php` - Moodle form used by students to file categorized appeals.
- `classes/form/participant_fields_form.php` - Moodle form for multilingual company participant-field definitions.
- `classes/form/consent_document_form.php` - Moodle form for publishing immutable multilingual consent versions.
- `classes/output/report_renderer.php` - Prepares report status, identity policy, violations, snapshots, clips, and retention data for templates.
- `templates/report_summary.mustache` - Displays a detailed proctoring report and protected evidence links.
- `amd/src/proctorcore.js` - Runs LiveKit capture, browser recording fallback, snapshots, interruption handling, and finalization.
- `tests/session_repository_test.php` - Tests policy precedence, threshold floors, and retention defaults.

## Schema overview

- `local_proctorcore_companycfg` - Per-company integration, retention, language, instruction, and feature settings.
- `local_proctorcore_faceenrol` - One reusable Server B face reference metadata record per Moodle user.
- `local_proctorcore_quizcfg` - Per-quiz proctoring enablement and gate requirements.
- `local_proctorcore_fields` - Configurable participant data fields such as IIN, department, or course-specific identifiers.
- `local_proctorcore_uservals` - Reusable per-user participant values with optional Moodle custom-profile synchronization.
- `local_proctorcore_consentdoc` - Consent document activation, ordering, and current-version pointers.
- `local_proctorcore_consentver` - Immutable Kazakh, Russian, and English consent document versions.
- `local_proctorcore_consentlog` - Append-only proof of the exact document set personally accepted by a user.
- `local_proctorcore_sessions` - Official proctoring session record linked to Moodle course, quiz, attempt, user, and Server B session ids.
- `local_proctorcore_fieldvals` - Captured participant field values for each proctoring session.
- `local_proctorcore_checks` - Equipment, browser, speed, camera, microphone, and lighting check results.
- `local_proctorcore_rulesack` - Logged acknowledgement of exam rules before admission to the quiz.
- `local_proctorcore_violations` - AI/server and manually flagged violations with timestamps, severity, source, and metadata.
- `local_proctorcore_assets` - References to PDFs, videos, clips, snapshots, ID images, room scans, and violation acts with retention status.
- `local_proctorcore_webhooks` - Raw inbound Server B events plus processing status for idempotency and troubleshooting.
- `local_proctorcore_appeals` - Appeal requests, reasons, reviewer decisions, and evidence-hold state linkage.
- `local_proctorcore_audit` - Append-only administrator, coordinator, proctor, and integration action log.
# ProctorCore local plugin - release 0.14.0

Technical component: `local_proctorcore`  
Install location: `local/proctorcore`

Implemented scope:

- Section 1.1 - browser camera/microphone capture, key snapshots, evidence metadata,
  retention and automatic deletion.
- Section 1.2 - first-exam face enrollment, reusable Server B reference storage, and subsequent-exam verification.
- Section 1.3 - gaze/head-direction, tab/window, no-face, additional-face, media-ended,
  and periodic identity monitoring with violation snapshots.
- Section 3.1 — automatic HTML and PDF proctoring reports, violations, snapshots,
  protected video links, student/company/global access control, and view/download audit.
- Section 3.2 — report retention for at least 183 days and evidence cleanup.
- Section 4.1 — Moodle/IOMAD to Server B session creation.
- Section 4.2 — signed Server B Passed/Failed result webhook.
- Section 5.1 — browser/device preflight and administrator preview.
- Section 5.3 — heartbeat, interruption detection, reconnect window, and same-attempt recovery.
- Section 6.1/A.4 - reusable multilingual participant fields, preflight validation,
  Moodle profile mapping, and report snapshots.
- Section 7.1 - configurable exam rules with explicit hash-bound acknowledgement.
- Section 8.1 - categorized appeals, authorised review, notifications, evidence holds,
  retry, and course-completion release.
- Feedback A.2 - multilingual versioned consent documents, mandatory personal
  acceptance, impersonation protection, renewed consent, and CSV evidence export.

## Section 3.1 routes

- `/local/proctorcore/reports.php` — authorised report list and detail page.
- `/local/proctorcore/download_report.php?sessionid=ID` — protected PDF download.
- `/local/proctorcore/evidence.php?assetid=ID` — protected snapshot/video gateway.

Reports are generated:

1. provisionally when the Moodle Quiz attempt is submitted;
2. again when the final Server B Passed/Failed webhook is processed; and
3. by a five-minute scheduled task when a report is missing or stale.

Generated PDFs are stored through Moodle's private File API in the system context,
registered in `local_proctorcore_assets`, kept for at least 183 days, and removed by
the existing retention task after expiry.

## Access

- A test-taker can view and download only their own report.
- A company coordinator needs `local/proctorcore:viewcompanyreports` and is restricted
  to companies assigned in IOMAD.
- A coordinator needs `local/proctorcore:exportreports` to download reports for others.
- A global SENTAL reporting role needs `local/proctorcore:viewallreports`.
- Site administrators can view all reports.

## Companion plugin

Use `quizaccess_proctorcore` release 0.14.0 or newer. It requires
`local_proctorcore >= 2026091200`.

For private Server B evidence, the development/production Server B must expose
a Bearer-authenticated `GET /api/v1/assets/{externalId}/content` endpoint. Moodle
proxies the file only after checking student, company coordinator, or global
administrator permissions.


## Section 3.1 report interface (v0.9.1)

- Quiz teachers with `mod/quiz:viewreports` receive a **Proctoring reports** entry in the Quiz settings/More menu.
- The list is filtered to the selected Quiz and shows session, student, course, Quiz, end time, result, violation count, View, and Download PDF.
- Students see only their own reports; company coordinators and site administrators retain their tenant-aware scopes.


## Sections 1.2 and 1.3

Identity verification, behaviour analysis, media capture, clips, and PDF generation
belong to Server B. Configure `serverbaseurl`, `serverapikey`, `identityenabled`,
`identitymismatchmode`, and `monitoringenabled` in Site administration. Moodle keeps the official session,
result, appeal, retention, and report-link records.
