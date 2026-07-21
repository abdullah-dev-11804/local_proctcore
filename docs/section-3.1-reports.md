# Section 3.1 — Proctoring reports

## Automatic lifecycle

- `mod_quiz\event\attempt_submitted` generates a provisional report.
- `session.completed` or `session.failed` webhook applies Passed/Failed and regenerates it.
- `generate_reports_task` runs every five minutes and repairs missing/stale reports.

## Data included

- Local and Server B session IDs.
- Student, IOMAD company, course, Quiz and attempt details.
- Start/end timestamps, duration, Quiz grade and percentage.
- Technical and identity status, result, risk score and retention dates.
- Violations ordered by exact occurrence timestamp.
- Identity, violation and submission snapshots.
- Protected evidence links for video clips.
- Configurable participant field values.

## Storage and retention

The PDF is generated with Moodle's TCPDF wrapper and saved using the Moodle File API:

- component: `local_proctorcore`
- file area: `reports`
- item id: local session id
- context: system

The asset record uses `storage=moodle_file` and `assettype=report`. The expiry is
calculated by `retention_policy` and is never less than 183 days after completion.

## Audit actions

- `report.generated`
- `report.generation_failed`
- `report.list_viewed`
- `report.viewed`
- `report.downloaded`
- `report.evidence_viewed`
- `report.evidence_downloaded`

## Protected Server B evidence contract

For `storage=server_b`, Moodle proxies the authorised file through
`evidence.php`. Server B must implement:

```text
GET /api/v1/assets/{externalId}/content
Authorization: Bearer <company Server B API key>
```

The endpoint must return the original private file bytes and a correct
`Content-Type`. It must not expose the storage path publicly. The included
Section 3.1 development Server B reference implements this contract.

## CLI acceptance test

```bash
sudo -u www-data php local/proctorcore/cli/test_report_generation.php --sessionid=25
```

Expected: `REPORT GENERATION: OK`, a non-zero PDF size, an expiry date at least
183 days after completion, and a report URL.
