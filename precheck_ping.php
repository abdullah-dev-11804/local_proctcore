<?php
// This file is part of Moodle - http://moodle.org/

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

require_login();
\core\session\manager::write_close();

$bytes = optional_param('bytes', 131072, PARAM_INT);
$bytes = min(262144, max(1024, $bytes));

header('Content-Type: application/octet-stream');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Length: ' . $bytes);

echo str_repeat('P', $bytes);
