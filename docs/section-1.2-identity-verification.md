# Section 1.2 - Identity verification

## Flow

1. The normal Section 5.1 equipment check starts the camera.
2. The candidate presses **Start identity check**.
3. The browser captures three frames: centre, left turn, right turn.
4. Moodle reads the candidate's protected Moodle profile image.
5. `local_proctorcore` sends the four images to the private ML service.
6. The ML service detects exactly one face, checks basic image quality, performs the left/right liveness challenge, and compares face embeddings.
7. A passed result is held in the authenticated Moodle session until Moodle creates the real Quiz attempt.
8. The live identity image is stored as protected evidence and `identitystatus` becomes `passed`.
9. A failure blocks Quiz entry. If a real session already exists, an `identity_substitution` violation is registered.

## Main Moodle files

- `identity_verify.php`
- `classes/local/identity_service.php`
- `classes/local/ml_client.php`
- `amd/src/identity_check.js`
- `amd/build/identity_check.min.js`

## ML endpoint

- `POST /api/v1/identity/verify`

## Result values

- `matched`
- `not_matched`
- `no_face`
- `multiple_faces`
- `reference_face_invalid`
- `verification_error`

## Security

- Candidate images are never sent to the browser after processing.
- ML credentials remain in Moodle server configuration.
- The identity evidence uses Moodle's private File API.
- The test must run over HTTPS in production.

## Operational note

The default cosine threshold is a starting value, not a universal production value. Calibrate it using consented, representative test images and keep a human-review path for disputed results.
