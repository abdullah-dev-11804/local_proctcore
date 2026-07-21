# Section 5.1 foundation — preflight gate and administrator preview

## Student flow

1. Student opens the Quiz and clicks **Attempt quiz now**.
2. Moodle's standard preflight form opens before the Quiz attempt is created.
3. ProctorCore automatically checks:
   - Chrome or Edge;
   - secure browser context/HTTPS when media is required;
   - Moodle network throughput;
   - camera permission and live video track;
   - microphone permission and live audio track;
   - approximate camera brightness;
   - camera-frame capture readiness.
4. Moodle keeps its Start attempt submit button disabled until all required checks pass.
5. After submission, Moodle creates the real Quiz attempt.
6. `notify_preflight_check_passed()` creates the local/Server B session, stores the check record, starts Server B, and redirects to the Quiz.
7. Section 5.3 heartbeats start on the attempt page.

## Administrator preview

Teachers/managers can open **Proctoring preview** from the Quiz view/settings navigation. Preview mode runs the same browser/device checks and Server B health check but creates no official attempt or session.

## Existing database

No schema change is needed. Successful check metadata is stored in:

- `local_proctorcore_sessions.techcheckstatus`
- `local_proctorcore_sessions.identitystatus`
- `local_proctorcore_sessions.servermetadata`
- `local_proctorcore_checks`
- `local_proctorcore_audit`

## Security notes

- Browser media APIs require HTTPS except on localhost.
- Browser/device values are client-reported readiness signals. Full biometric identity verification and AI violation analysis are separate features.
