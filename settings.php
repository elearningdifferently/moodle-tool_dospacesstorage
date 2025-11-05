<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Plugin administration pages are defined here.
 *
 * @package     tool_dospacesstorage
 * @copyright   2025 eLearning Differently
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Create a new category for DO Spaces Storage.
    $ADMIN->add('tools', new admin_category('tool_dospacesstorage', 
        get_string('pluginname', 'tool_dospacesstorage')));
    
    // Add debug log viewer link.
    $ADMIN->add('tool_dospacesstorage', new admin_externalpage(
        'tool_dospacesstorage_debuglog',
        get_string('debuglog', 'tool_dospacesstorage'),
        new moodle_url('/admin/tool/dospacesstorage/debug_log.php'),
        'moodle/site:config'
    ));
}
