<?php
/**
 * Download debug log file.
 */

require_once(__DIR__ . '/../../../config.php');

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$logfile = $CFG->dataroot . '/dospacesstorage.log';

if (!file_exists($logfile)) {
    die('Log file not found');
}

header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="dospacesstorage-' . date('Y-m-d-His') . '.log"');
header('Content-Length: ' . filesize($logfile));

readfile($logfile);
