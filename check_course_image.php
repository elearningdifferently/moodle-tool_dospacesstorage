<?php
/**
 * Debug script to check course image file details.
 * Usage: php admin/tool/dospacesstorage/check_course_image.php [courseid]
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Get course ID from command line or use default.
list($options, $unrecognized) = cli_get_params(
    array('courseid' => 3, 'help' => false),
    array('h' => 'help')
);

if ($options['help']) {
    echo "Check course image file details\n";
    echo "Usage: php check_course_image.php --courseid=3\n";
    exit(0);
}

$courseid = $options['courseid'];

echo "Checking course image for course ID: $courseid\n\n";

// Get course context.
$context = context_course::instance($courseid);

// Get all files in the course overviewfiles area.
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'course', 'overviewfiles', 0, 'filename', false);

if (empty($files)) {
    echo "No course image files found.\n";
    exit(0);
}

echo "Found " . count($files) . " file(s) in course overviewfiles area:\n\n";

foreach ($files as $file) {
    echo "========================================\n";
    echo "Filename: " . $file->get_filename() . "\n";
    echo "Contenthash: " . $file->get_contenthash() . "\n";
    echo "Filesize: " . $file->get_filesize() . " bytes\n";
    echo "Mimetype: " . $file->get_mimetype() . "\n";
    echo "Timecreated: " . date('Y-m-d H:i:s', $file->get_timecreated()) . "\n";
    echo "Timemodified: " . date('Y-m-d H:i:s', $file->get_timemodified()) . "\n";
    
    // Check if file is in DO Spaces.
    $filesystem = $fs->get_file_system();
    echo "File system class: " . get_class($filesystem) . "\n";
    
    if ($filesystem instanceof \tool_dospacesstorage\file_system) {
        echo "Using DO Spaces storage\n";
        
        // Check if file exists remotely.
        $exists = $filesystem->is_file_readable_remotely_by_hash($file->get_contenthash());
        echo "Exists in DO Spaces: " . ($exists ? 'YES' : 'NO') . "\n";
        
        // Try to get local path.
        echo "Attempting to fetch local path...\n";
        $path = $filesystem->get_local_path_from_storedfile($file, true);
        
        if ($path) {
            echo "Local path: $path\n";
            echo "File exists locally: " . (file_exists($path) ? 'YES' : 'NO') . "\n";
            if (file_exists($path)) {
                echo "File is readable: " . (is_readable($path) ? 'YES' : 'NO') . "\n";
                echo "Local filesize: " . filesize($path) . " bytes\n";
            }
        } else {
            echo "ERROR: Could not get local path\n";
        }
    } else {
        echo "Using standard file system (NOT DO Spaces)\n";
        $path = $filesystem->get_local_path_from_storedfile($file, false);
        echo "Local path: $path\n";
        echo "File exists: " . (file_exists($path) ? 'YES' : 'NO') . "\n";
    }
    
    echo "\n";
}

echo "Check /tmp/moodledata/dospacesstorage.log for detailed operation logs.\n";
