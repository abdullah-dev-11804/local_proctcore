<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_login();
redirect(new moodle_url('/local/proctorcore/reports.php'));
