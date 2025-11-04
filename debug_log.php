<?php
/**
 * Debug log viewer for DO Spaces storage plugin.
 * 
 * Access: /admin/tool/dospacesstorage/debug_log.php
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/admin/tool/dospacesstorage/debug_log.php'));
$PAGE->set_pagelayout('report');
$PAGE->set_title('DO Spaces Debug Log');
$PAGE->set_heading('DO Spaces Debug Log');

echo $OUTPUT->header();
echo $OUTPUT->heading('DO Spaces Storage Debug Log');

$logfile = $CFG->dataroot . '/dospacesstorage.log';

if (!file_exists($logfile)) {
    echo $OUTPUT->notification('Log file does not exist yet: ' . $logfile, 'info');
    echo '<p>The log file will be created when the plugin performs its first operation.</p>';
} else {
    $filesize = filesize($logfile);
    $lines = file($logfile);
    $linecount = count($lines);
    
    echo '<div class="alert alert-info">';
    echo '<strong>Log file:</strong> ' . $logfile . '<br>';
    echo '<strong>Size:</strong> ' . round($filesize / 1024, 2) . ' KB<br>';
    echo '<strong>Lines:</strong> ' . $linecount;
    echo '</div>';
    
    // Show last 100 lines
    $showlines = array_slice($lines, -100);
    
    echo '<h3>Last 100 log entries:</h3>';
    echo '<pre style="background: #f5f5f5; padding: 15px; border: 1px solid #ddd; overflow-x: auto; max-height: 600px; overflow-y: scroll;">';
    echo htmlspecialchars(implode('', $showlines));
    echo '</pre>';
    
    // Download link
    echo '<p><a href="' . $CFG->wwwroot . '/admin/tool/dospacesstorage/download_log.php" class="btn btn-primary">Download Full Log</a></p>';
}

echo $OUTPUT->footer();
