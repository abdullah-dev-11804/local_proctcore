# Section 5.3 — Connection recovery

## Requirement

A test-taker may return within 10 minutes after a brief connection loss. The same Moodle quiz attempt is resumed, previously saved answers remain, and the timer is not restarted.

## Important design decision

Moodle Quiz remains the source of truth for answers and time. ProctorCore does not create a new `quiz_attempts` record and never changes `quiz_attempts.timestart` during reconnect.

## State flow

```text
active
  -> no heartbeat for configured threshold
interrupted
  -> reconnect before deadline
active (same local session, same Server B session, same quiz attempt)

interrupted
  -> reconnect deadline expires
abandoned locally
  -> Server B /fail endpoint
  -> normal Section 4.2 Failed webhook
```

## Existing database fields used

No install.xml or upgrade.php change is required.

- `local_proctorcore_sessions.status`
- `local_proctorcore_sessions.lastheartbeat`
- `local_proctorcore_sessions.startedat`
- `local_proctorcore_sessions.endedat`
- `local_proctorcore_sessions.closedreason`
- `local_proctorcore_sessions.servermetadata`
- `local_proctorcore_quizcfg.allowresume`
- `local_proctorcore_quizcfg.resumewindowsecs`

Interruption time, reconnect deadline, resume count, and sync errors are stored under `servermetadata.connectionRecovery`.

## Main Moodle files

- `classes/local/connection_recovery_service.php`
- `classes/local/session_repository.php`
- `classes/local/server_client.php`
- `classes/task/connection_recovery_task.php`
- `heartbeat.php`
- `reconnect.php`
- `amd/src/session_heartbeat.js`
- `cli/test_connection_recovery.php`

## Server B API additions

```text
POST /api/v1/sessions/{sessionId}/heartbeat
POST /api/v1/sessions/{sessionId}/interrupt
POST /api/v1/sessions/{sessionId}/resume
POST /api/v1/sessions/{sessionId}/fail
```

Server B must preserve the original `timer` object created with the session. The resume endpoint must not replace `timerStartedAt` or issue a new session ID.

## Frontend integration

After the proctoring session is active, Dev 1 or the quiz access rule calls:

```php
local_proctorcore_require_heartbeat((int) $session->id);
```

The JavaScript module sends heartbeats at the configured interval. The frontend may listen for:

```text
proctorcore:heartbeat
proctorcore:connectionlost
proctorcore:interrupted
```

The reconnect button should use:

```php
$url = local_proctorcore_get_reconnect_url((int) $attemptid);
```

## Cron

The `connection_recovery_task` runs each minute. It marks stale active sessions interrupted and marks sessions abandoned after the reconnect deadline.

## Production note

The local `abandoned` status blocks reconnect immediately after expiry. Server B should then send the normal signed Section 4.2 `failed` result webhook so Moodle receives the official final result exactly once.
