<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * DigitalOcean Spaces file system implementation.
 *
 * @package     tool_dospacesstorage
 * @copyright   2025 eLearning Differently
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_dospacesstorage;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/admin/tool/dospacesstorage/classes/s3_client.php');
require_once($CFG->dirroot . '/admin/tool/dospacesstorage/classes/cache_manager.php');

/**
 * DigitalOcean Spaces file system.
 *
 * Implements Moodle's file_system interface to store files in DO Spaces
 * with local caching for performance.
 */
class file_system extends \file_system {

    /** @var s3_client S3 client for DO Spaces */
    protected $client;

    /** @var cache_manager Local cache manager */
    protected $cache;

    /** @var array Configuration */
    protected $config;

    /**
     * Constructor.
     */
    public function __construct() {
        global $CFG;

        // Get configuration
        $this->config = $CFG->dospacesstorage ?? [];
        
        // Validate required settings
        $required = ['key', 'secret', 'bucket', 'region', 'endpoint'];
        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                throw new \moodle_exception('missingconfig', 'tool_dospacesstorage', '', $key);
            }
        }

        // Set defaults
        $this->config['cache_path'] = $this->config['cache_path'] ?? '/tmp/moodledata/spacescache';
        $this->config['cache_max_size'] = $this->config['cache_max_size'] ?? 1073741824; // 1GB
        $this->config['cdn_endpoint'] = $this->config['cdn_endpoint'] ?? '';

        // Initialize S3 client
        $this->client = new s3_client(
            $this->config['key'],
            $this->config['secret'],
            $this->config['region'],
            $this->config['endpoint'],
            $this->config['cdn_endpoint']
        );

        // Initialize cache manager
        $this->cache = new cache_manager(
            $this->config['cache_path'],
            $this->config['cache_max_size']
        );
    }

    /**
     * Add file to storage.
     *
     * @param string $pathname Path to local file
     * @param string $contenthash Content hash (SHA1)
     * @return bool Success
     */
    public function add_file_from_path($pathname, $contenthash) {
        $key = $this->get_remote_path_from_hash($contenthash);
        
        try {
            // Upload to Spaces
            $this->client->put_object($this->config['bucket'], $key, $pathname);
            
            // Add to local cache
            $this->cache->add($contenthash, $pathname);
            
            return true;
        } catch (\Exception $e) {
            debugging('Failed to upload file to DO Spaces: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Add file from string content.
     *
     * @param string $content File content
     * @param string $contenthash Content hash
     * @return bool Success
     */
    public function add_file_from_string($content, $contenthash) {
        // Write to temp file first
        $tempfile = tempnam(sys_get_temp_dir(), 'moodle_');
        file_put_contents($tempfile, $content);
        
        $result = $this->add_file_from_path($tempfile, $contenthash);
        
        @unlink($tempfile);
        return $result;
    }

    /**
     * Get local path for contenthash (downloads from Spaces if needed).
     *
     * @param string $contenthash Content hash
     * @param bool $fetchifnotfound Download if not in cache
     * @return string|bool Local file path or false
     */
    public function get_local_path_from_hash($contenthash, $fetchifnotfound = false) {
        // Check cache first
        $cachedpath = $this->cache->get($contenthash);
        if ($cachedpath && file_exists($cachedpath)) {
            return $cachedpath;
        }

        if (!$fetchifnotfound) {
            return false;
        }

        // Download from Spaces
        $key = $this->get_remote_path_from_hash($contenthash);
        $localpath = $this->cache->get_cache_path($contenthash);

        try {
            // Ensure directory exists
            $dir = dirname($localpath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            // Download file
            $this->client->get_object($this->config['bucket'], $key, $localpath);
            
            // Add to cache
            $this->cache->add($contenthash, $localpath);
            
            return $localpath;
        } catch (\Exception $e) {
            debugging('Failed to download file from DO Spaces: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Get remote storage path from contenthash.
     *
     * @param string $contenthash Content hash
     * @return string Remote key/path
     */
    public function get_remote_path_from_hash($contenthash) {
        // Moodle standard: split hash into directory structure
        // e.g., abc123... becomes ab/c1/abc123...
        $l1 = substr($contenthash, 0, 2);
        $l2 = substr($contenthash, 2, 2);
        return "$l1/$l2/$contenthash";
    }

    /**
     * Check if file exists in storage.
     *
     * @param string $contenthash Content hash
     * @return bool Exists
     */
    public function is_file_readable_remotely_by_hash($contenthash) {
        $key = $this->get_remote_path_from_hash($contenthash);
        
        try {
            return $this->client->head_object($this->config['bucket'], $key);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Remove file from storage.
     *
     * @param string $contenthash Content hash
     * @return bool Success
     */
    public function remove_file($contenthash) {
        $key = $this->get_remote_path_from_hash($contenthash);
        
        try {
            // Remove from Spaces
            $this->client->delete_object($this->config['bucket'], $key);
            
            // Remove from cache
            $this->cache->remove($contenthash);
            
            return true;
        } catch (\Exception $e) {
            debugging('Failed to remove file from DO Spaces: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Get file content.
     *
     * @param string $contenthash Content hash
     * @return string|bool File content or false
     */
    public function get_content($contenthash) {
        $path = $this->get_local_path_from_hash($contenthash, true);
        if ($path && file_exists($path)) {
            return file_get_contents($path);
        }
        return false;
    }

    /**
     * List all files in storage (not implemented - not needed for normal operation).
     *
     * @return array Empty array
     */
    public function get_all_files() {
        // Not implemented - would require listing entire bucket
        return [];
    }
}
