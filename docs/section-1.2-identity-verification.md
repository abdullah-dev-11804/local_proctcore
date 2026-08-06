# Section 1.2 - Identity verification

## Flow

1. The normal Section 5.1 equipment check starts the camera.
2. If the user has no active face reference, Moodle shows the profile full name and the future-use notice.
3. The candidate explicitly confirms the name and notice, then presses **Start identity check**.
4. The browser captures a short straight-face burst.
5. On the first proctored exam, Server B accepts only a high-quality reference with adequate lighting, framing, and exactly one frontal face.
6. Moodle stores the confirmation timestamp/name and Server B reference id/key. Server B stores the actual reference image.
7. On later proctored exams, Server B compares the live burst against the stored reference.
8. Moodle applies the configured mismatch behaviour: block, allow and flag manual review, or allow and mark proctoring failed.
9. A passed or allowed-for-review result is held in the authenticated Moodle session until Moodle creates the real Quiz attempt.

## Main implementation files

- Server B / iframe frontend — face reference storage, quality checks, face comparison, retry/review decisions.
- `classes/local/face_enrollment_repository.php` — Moodle-side enrollment confirmation and Server B reference metadata.
- `classes/local/server_client.php` — Moodle-side Server B API client.
- `classes/local/webhook_processor.php` — final identity/report/result events from Server B.

## ML endpoint

- `POST /api/v1/identity/references/enroll`
- `POST /api/v1/identity/references/verify`
- `DELETE /api/v1/identity/references/{userId}`

## Result values

- `matched`
- `enrolled`
- `needs_review`
- `failed_allowed`
- `no_face`
- `multiple_faces`
- `low_light`
- `blurry`
- `face_not_centered`
- `verification_error`

## Security

- Candidate images are never sent to the browser after processing.
- Server B API credentials remain in Moodle server configuration.
- The reusable reference image is stored on Server B in Kazakhstan, not in Moodle.
- The test must run over HTTPS in production.

## Operational note

The default cosine threshold is a starting value, not a universal production value. Calibrate it using consented, representative test images and keep a human-review path for disputed results.
