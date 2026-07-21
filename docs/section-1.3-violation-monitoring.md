# Section 1.3 - Behaviour and violation monitoring

## Implemented mandatory detections

- Gaze/head direction: `looking_away`
- Browser tab/background change: `tab_hidden`
- Browser window loses focus: `window_blur`
- Additional faces: `multiple_faces`
- No visible face: `no_face`
- Camera/microphone track termination
- Periodic identity re-verification: `different_person`

## Flow

1. The existing Section 1.1 camera stream remains active during the Quiz.
2. Every configured interval, the browser sends a reduced JPEG frame to Moodle.
3. Moodle forwards the frame to the private ML service.
4. The ML service returns face count, approximate head direction and optional identity similarity.
5. Moodle requires the condition to remain active for the configured number of seconds.
6. A cooldown prevents duplicate violations from being created continuously.
7. Confirmed violations are written to `local_proctorcore_violations` with timestamp, severity and model metadata.
8. A `proctorcore:violation` browser event asks Section 1.1 capture to save a matching snapshot.
9. The report service automatically lists the violation and protected evidence.

## Main Moodle files

- `monitor.php`
- `classes/local/violation_service.php`
- `classes/local/violation_repository.php`
- `amd/src/violation_monitor.js`
- `amd/build/violation_monitor.min.js`

## ML endpoint

- `POST /api/v1/monitor/analyse`

## Default confirmation rules

- No face: 3 seconds
- Multiple faces: 3 seconds
- Looking away: 5 seconds
- Same violation cooldown: 30 seconds
- Periodic identity recheck: 60 seconds

All values are administrator configurable. The system records evidence and risk indicators; it should not treat one imperfect ML result as final proof of cheating.
