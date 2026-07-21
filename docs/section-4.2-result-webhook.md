# Section 4.2 — Server B result callback

## Purpose

When a proctoring session ends, Server B sends the final `passed` or `failed`
result to Moodle. Moodle validates the signed event, stores it once, updates the
official ProctorCore session, creates an audit row, and fires a Moodle event for
future exam-protocol integration.

## Endpoint

`POST /local/proctorcore/webhook.php`

Headers:

```http
Content-Type: application/json
X-ProctorCore-Signature: sha256=<HMAC-SHA256 hexadecimal digest>
```

The HMAC is calculated over the **exact raw JSON body** using the effective
company webhook secret.

## Canonical payload

```json
{
  "eventId": "EVT-20260715-0001",
  "eventType": "session.completed",
  "sessionId": "PROC-20260715-A8F92C",
  "moodleSessionId": 25,
  "companyId": 3,
  "attemptId": 105,
  "userId": 42,
  "status": "completed",
  "result": "passed",
  "reasonCode": "no_critical_violations",
  "reason": "Dummy development result",
  "completedAt": "2026-07-15T19:15:00Z"
}
```

Supported final event types:

- `session.completed`
- `session.failed`

Supported result values:

- `passed`
- `failed`

## Successful response

```json
{
  "accepted": true,
  "status": "processed",
  "eventid": "EVT-20260715-0001",
  "sessionid": 25
}
```

A repeated `eventId` returns status `duplicate` and does not update the session
a second time.

## Security and consistency

- HMAC-SHA256 validation with `hash_equals()`.
- Tenant/company, quiz-attempt and student identifiers are checked against the
  local session.
- Event IDs are unique in the webhook inbox.
- A final result cannot be changed from passed to failed or vice versa by a
  later contradictory event.
- The original JSON payload is retained in the webhook inbox for support and
  audit purposes.
- A Moodle `local_proctorcore\event\proctoring_result_received` event is fired
  after the transaction commits. The future exam-protocol integration should
  observe this event.

## Development test

```bash
sudo -u www-data php local/proctorcore/cli/send_test_webhook.php \
  --sessionid=25 \
  --result=passed
```
