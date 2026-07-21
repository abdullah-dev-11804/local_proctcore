<?php
// This file is part of Moodle - http://moodle.org/

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'sessionid' => null,
    'action' => 'status',
    'secondsago' => null,
    'help' => false,
], [
    's' => 'sessionid',
    'a' => 'action',
    'h' => 'help',
]);

if ($options['help'] || empty($options['sessionid'])) {
    echo "Section 5.3 connection recovery test\n\n";
    echo "Usage:\n";
    echo "  php local/proctorcore/cli/test_connection_recovery.php --sessionid=25 --action=status\n";
    echo "  php local/proctorcore/cli/test_connection_recovery.php --sessionid=25 --action=activate\n";
    echo "  php local/proctorcore/cli/test_connection_recovery.php --sessionid=25 --action=heartbeat\n";
    echo "  php local/proctorcore/cli/test_connection_recovery.php --sessionid=25 --action=interrupt --secondsago=90\n";
    echo "  php local/proctorcore/cli/test_connection_recovery.php --sessionid=25 --action=resume\n";
    echo "  php local/proctorcore/cli/test_connection_recovery.php --sessionid=25 --action=expire --secondsago=700\n";
    exit($options['help'] ? 0 : 1);
}

$sessionid = (int) $options['sessionid'];
$action = clean_param((string) $options['action'], PARAM_ALPHANUMEXT);
$repository = new \local_proctorcore\local\session_repository();
$service = new \local_proctorcore\local\connection_recovery_service();
$session = $repository->get_by_id($sessionid);

$attemptbefore = $DB->get_record('quiz_attempts', ['id' => (int) $session->attemptid],
    'id,userid,state,timestart,timefinish', MUST_EXIST);

try {
    switch ($action) {
        case 'activate':
            $repository->update_status($sessionid, 'active');
            $repository->update_heartbeat($sessionid, time());
            echo "Session activated.\n";
            break;

        case 'heartbeat':
            $result = $service->heartbeat($sessionid, (int) $session->userid);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            break;

        case 'interrupt':
            $secondsago = $options['secondsago'] !== null ? (int) $options['secondsago'] : 90;
            $repository->update_status($sessionid, 'active');
            $repository->update_heartbeat($sessionid, time() - max(60, $secondsago));
            $result = $service->process_timeouts(200);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            break;

        case 'resume':
            $result = $service->resume_attempt((int) $session->attemptid, (int) $session->userid);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            break;

        case 'expire':
            $window = $service->get_resume_window_seconds($session);
            $secondsago = $options['secondsago'] !== null
                ? (int) $options['secondsago']
                : $window + $service->get_heartbeat_grace_seconds() + 60;
            $repository->update_status($sessionid, 'active');
            $repository->update_heartbeat($sessionid, time() - max($window + 60, $secondsago));
            $result = $service->process_timeouts(200);
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            break;

        case 'status':
            break;

        default:
            throw new coding_exception('Unknown action: ' . $action);
    }

    $session = $repository->get_by_id($sessionid);
    $attemptafter = $DB->get_record('quiz_attempts', ['id' => (int) $session->attemptid],
        'id,userid,state,timestart,timefinish', MUST_EXIST);

    $output = [
        'session' => [
            'id' => (int) $session->id,
            'companyId' => (int) $session->companyid,
            'attemptId' => (int) $session->attemptid,
            'userId' => (int) $session->userid,
            'serverSessionId' => (string) $session->server_sessionid,
            'status' => (string) $session->status,
            'result' => (string) $session->result,
            'startedAt' => $session->startedat !== null ? (int) $session->startedat : null,
            'lastHeartbeat' => $session->lastheartbeat !== null ? (int) $session->lastheartbeat : null,
            'endedAt' => $session->endedat !== null ? (int) $session->endedat : null,
            'closedReason' => $session->closedreason,
            'connectionRecovery' => $repository->get_connection_recovery($session),
        ],
        'quizAttempt' => [
            'id' => (int) $attemptafter->id,
            'state' => (string) $attemptafter->state,
            'timestartBefore' => (int) $attemptbefore->timestart,
            'timestartAfter' => (int) $attemptafter->timestart,
            'timerReset' => (int) $attemptbefore->timestart !== (int) $attemptafter->timestart,
            'sameAttempt' => (int) $attemptbefore->id === (int) $attemptafter->id,
        ],
    ];

    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    cli_error($exception->getMessage());
}
