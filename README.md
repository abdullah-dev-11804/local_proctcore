# ProctorCore local plugin - release 0.10.0

Technical component: `local_proctorcore`  
Install location: `local/proctorcore`

Implemented scope:

- Section 1.1 - browser camera/microphone capture, key snapshots, evidence metadata,
  retention and automatic deletion.
- Section 1.2 - Moodle-profile face verification with an active centre/left/right liveness challenge.
- Section 1.3 - gaze/head-direction, tab/window, no-face, additional-face, media-ended,
  and periodic identity monitoring with violation snapshots.
- Section 3.1 — automatic HTML and PDF proctoring reports, violations, snapshots,
  protected video links, student/company/global access control, and view/download audit.
- Section 3.2 — report retention for at least 183 days and evidence cleanup.
- Section 4.1 — Moodle/IOMAD to Server B session creation.
- Section 4.2 — signed Server B Passed/Failed result webhook.
- Section 5.1 — browser/device preflight and administrator preview.
- Section 5.3 — heartbeat, interruption detection, reconnect window, and same-attempt recovery.

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

Use the matched `quizaccess_proctorcore` release 0.10.0. It requires
`local_proctorcore >= 2026072003`.

For private Server B evidence, the development/production Server B must expose
a Bearer-authenticated `GET /api/v1/assets/{externalId}/content` endpoint. Moodle
proxies the file only after checking student, company coordinator, or global
administrator permissions.


## Section 3.1 report interface (v0.9.1)

- Quiz teachers with `mod/quiz:viewreports` receive a **Proctoring reports** entry in the Quiz settings/More menu.
- The list is filtered to the selected Quiz and shows session, student, course, Quiz, end time, result, violation count, View, and Download PDF.
- Students see only their own reports; company coordinators and site administrators retain their tenant-aware scopes.


## Sections 1.2 and 1.3

The private ML service runs separately from Moodle, normally on `127.0.0.1:8091`.
Configure `mlserviceurl`, `mlapikey`, `identityenabled`, and `monitoringenabled` in
Site administration. See `docs/section-1.2-identity-verification.md` and
`docs/section-1.3-violation-monitoring.md`.
