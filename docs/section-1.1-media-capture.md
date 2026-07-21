# Section 1.1 — Camera, microphone, snapshots, and retention

## Implemented behaviour

The Moodle attempt page now:

1. requests a short-lived LiveKit participant token from Server B;
2. connects the candidate's camera and microphone in the browser;
3. starts an idempotent server-side recording segment;
4. requests snapshots for identity verification, violations, and final submission;
5. locks Quiz answer controls until capture is connected;
6. preserves/finalises the partial segment after a detected connection or media failure;
7. starts a new segment when the same Moodle attempt is resumed;
8. receives each completed snapshot/clip through a signed `asset.captured` webhook;
9. applies final retention dates when `session.completed` or `session.failed` arrives; and
10. deletes expired, non-held evidence through the scheduled retention task.

Moodle stores references and audit metadata. Server B/LiveKit stores the binary media.
A permanent public media URL is intentionally not stored in Moodle.

## Local plugin settings

Open:

`Site administration → Plugins → Local plugins → ProctorCore`

Important settings:

- **Minimum internet speed (Mbps)** — default `5.0`.
- **Minimum lighting score (1–255)** — default `35`.
- **LiveKit browser client URL** — HTTPS URL of a pinned UMD bundle exposing
  `window.LivekitClient`. Server B can instead return `clientScriptUrl`.
- Server B URL/API key/webhook secret.
- Report/video/appeal retention.
- Heartbeat and reconnect thresholds.

Both the administrator preview and student preflight receive the configured
internet and lighting thresholds. The server validates the submitted measurements
again before accepting the preflight result.

## Server B API contract expected by this release

### Create browser media token

```http
POST /api/v1/sessions/{serverSessionId}/media-token
```

Compatible response:

```json
{
  "url": "wss://livekit.example.kz",
  "token": "short-lived-participant-token",
  "roomId": "room_123",
  "clientScriptUrl": "https://static.example.kz/livekit-client.umd.min.js",
  "tokenExpiresAt": "2026-07-17T18:10:00Z"
}
```

`url` and `token` are mandatory. `clientScriptUrl` may be omitted when configured
in Moodle.

### Start recording segment

```http
POST /api/v1/sessions/{serverSessionId}/recording/start
```

Moodle sends the local session/attempt/company/user IDs, segment number, reason,
UTC start time, and an idempotency key such as `recording-start-45-2`.

### Stop/finalise recording segment

```http
POST /api/v1/sessions/{serverSessionId}/recording/stop
```

Server B must finalise all media already received and return success even when the
same idempotency key is retried.

### Capture snapshot

```http
POST /api/v1/sessions/{serverSessionId}/snapshots
```

Supported reasons:

- `identity_verification`
- `violation`
- `submission`
- `manual_proctor`

### Delete expired asset

```http
DELETE /api/v1/assets/{assetId}
```

Deletion must be idempotent. An already deleted asset should still return a 2xx
result so Moodle can mark the local reference deleted.

## `asset.captured` webhook

Server B sends one signed webhook after each snapshot or recording segment becomes
available:

```json
{
  "eventId": "evt_asset_001",
  "eventType": "asset.captured",
  "sessionId": "srv_abc",
  "moodleSessionId": 45,
  "companyId": 7,
  "attemptId": 9001,
  "userId": 88,
  "asset": {
    "assetId": "asset_987",
    "type": "video_clip",
    "reason": "connection_lost",
    "capturedAt": "2026-07-17T15:20:00Z",
    "availableAt": "2026-07-17T15:20:08Z",
    "violationId": null,
    "mimeType": "video/webm",
    "sizeBytes": 18472512,
    "checksum": "sha256hexvalue",
    "metadata": {
      "recordingSegment": 1
    }
  }
}
```

The webhook uses the existing `X-ProctorCore-Signature` HMAC-SHA256 mechanism.
Duplicate event IDs and duplicate tenant/session-scoped asset IDs are idempotent.

## Main implementation files

- `capture.php` — authenticated browser capture endpoint.
- `amd/src/proctorcore.js` — LiveKit camera/microphone attempt-page client.
- `classes/local/capture_service.php` — authorised recording/snapshot coordinator.
- `classes/local/server_client.php` — Server B media API methods.
- `classes/local/webhook_processor.php` — `asset.captured` plus final result events.
- `classes/observer.php` and `db/events.php` — authoritative Quiz submission fallback.
- `classes/task/cleanup_retention_task.php` — automatic evidence deletion.
- `classes/local/asset_repository.php` — tenant/session-scoped asset references.
- `settings.php` — internet, lighting, LiveKit, retention, and recovery settings.

## Connection-loss flow

```text
heartbeat/media failure
    → finalise current recording segment
    → preserve Server B media
    → mark Moodle session interrupted
    → retain the same Moodle Quiz attempt and timer
    → candidate reconnects within the configured window
    → new browser connection and new recording segment
```

Normal Quiz page navigation does not finalise the segment. Final submission is
handled by both browser keepalive requests and Moodle's `attempt_submitted` event,
so auto-submit and early browser navigation still have an idempotent server-side
fallback.

## Deployment notes

- Install local plugin at `local/proctorcore`.
- Install companion access rule at `mod/quiz/accessrule/proctorcore`.
- Run Moodle upgrade and purge caches after replacing both plugins.
- Use HTTPS for Moodle, Server B, LiveKit, and the LiveKit client script.
- Build/minify AMD sources with the normal Moodle JavaScript toolchain before a
  production release. The supplied `amd/build` files mirror the source so the
  package remains immediately testable.
- The Moodle code is only one side of Section 1.1. Server B must implement the API
  contract above and produce the signed asset/final webhooks.
