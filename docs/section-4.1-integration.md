# Section 4.1 — Moodle/IOMAD integration

## Purpose

`local_proctorcore` is the Moodle-side integration and official session record. It creates an idempotent local record for each quiz attempt, resolves the IOMAD company, calls Server B, and stores the returned external session id.

## Flow

1. A quiz-access or precheck component calls `local_proctorcore_create_session_for_attempt($attemptid)`.
2. `tenant_resolver` determines the IOMAD company using the active selected company, user membership, and course allocation.
3. `session_repository` creates one local record per company and quiz attempt.
4. `company_config_repository` resolves global/per-company integration settings.
5. `server_client` sends `POST /api/v1/sessions` to Server B.
6. The Server B session id and response metadata are stored in `local_proctorcore_sessions`.
7. Repeating the call returns the existing record instead of creating a duplicate external session.

## Required Server B endpoints

- `GET /api/health`
- `POST /api/v1/sessions`
- `GET /api/v1/sessions/{sessionId}`
- `POST /api/v1/sessions/{sessionId}/start`
- `POST /api/v1/sessions/{sessionId}/heartbeat`

## Minimum create-session response

```json
{
  "success": true,
  "session": {
    "sessionId": "PROC-20260715-A8F92C",
    "status": "created"
  }
}
```

## IOMAD compatibility

The resolver supports legacy IOMAD tables (`company_users`, `company_course`) and newer names (`local_iomad_company_users`, `local_iomad_company_courses`). On a standard Moodle installation it uses company id `0`.
