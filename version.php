<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Plugin version and other meta-data are defined here.
 *
 * @package     tool_dospacesstorage
 * @copyright   2025 eLearning Differently
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin = new stdClass();
$plugin->component = 'tool_dospacesstorage';
$plugin->release = '1.3.2';
$plugin->version = 2025110408;
$plugin->requires = 2023100900; // Moodle 4.3+
$plugin->maturity = MATURITY_STABLE;
