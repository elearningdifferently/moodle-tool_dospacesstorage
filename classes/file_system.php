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
     * Log debug message to file.
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    private function log_debug($message, $context = []) {
        global $CFG;
        
        $logfile = $CFG->dataroot . '/dospacesstorage.log';
        $timestamp = date('Y-m-d H:i:s');
        $contextstr = !empty($context) ? ' | ' . json_encode($context) : '';
        $logline = "[$timestamp] $message$contextstr\n";
        
        error_log($logline, 3, $logfile);
    }

    /**
     * Constructor.
     */
    public function __construct() {
        global $CFG;

        $this->log_debug('DO Spaces file_system: Constructor called', [
            'script' => $_SERVER['SCRIPT_NAME'] ?? 'cli',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'n/a',
        ]);

        // Get configuration
        $this->config = $CFG->dospacesstorage ?? [];
        
        // Validate required settings
        $required = ['key', 'secret', 'bucket', 'region', 'endpoint'];
        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                $this->log_debug("DO Spaces file_system: Missing config: $key");
                throw new \moodle_exception('missingconfig', 'tool_dospacesstorage', '', $key);
            }
        }

        // Set defaults
        $this->config['cache_path'] = $this->config['cache_path'] ?? '/tmp/moodledata/spacescache';
        $this->config['cache_max_size'] = $this->config['cache_max_size'] ?? 1073741824; // 1GB
        $this->config['cdn_endpoint'] = $this->config['cdn_endpoint'] ?? '';

        $this->log_debug('DO Spaces file_system: Configuration loaded', [
            'bucket' => $this->config['bucket'],
            'region' => $this->config['region'],
            'cache_path' => $this->config['cache_path'],
            'cdn_enabled' => !empty($this->config['cdn_endpoint']),
        ]);

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

        $this->log_debug('DO Spaces file_system: Initialized successfully');
    }

    /**
     * Add file to storage.
     *
     * @param string $pathname Path to local file
     * @param string|null $contenthash Content hash (SHA1). If null, computed from file.
     * @return array (contenthash, filesize, newfile)
     */
    public function add_file_from_path($pathname, $contenthash = null) {
        global $CFG;
        [$contenthash, $filesize] = \file_system::validate_hash_and_file_size($contenthash, $pathname);

        $key = $this->get_s3_key_from_hash($contenthash);
        
        $this->log_debug('add_file_from_path called', [
            'contenthash' => substr($contenthash, 0, 8) . '...',
            'pathname' => $pathname,
            'filesize' => $filesize,
            'key' => $key,
        ]);
        
        try {
            $start = microtime(true);
            
            // Upload to Spaces
            $this->client->put_object($this->config['bucket'], $key, $pathname);
            
            $duration = round((microtime(true) - $start) * 1000, 2);
            $this->log_debug('Upload completed', [
                'contenthash' => substr($contenthash, 0, 8) . '...',
                'duration_ms' => $duration,
            ]);
            
            // Add to local cache (store a local copy path consistent with cache manager)
            $cachepath = $this->cache->get_cache_path($contenthash);
            $dir = dirname($cachepath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            // Copy local source into cache to avoid re-download in this request.
            @copy($pathname, $cachepath);
            $this->cache->add($contenthash, $cachepath);
            
            // Return expected tuple
            return [$contenthash, $filesize, true];
        } catch (\Exception $e) {
            $this->log_debug('Upload failed', [
                'contenthash' => substr($contenthash, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
            debugging('Failed to upload file to DO Spaces: ' . $e->getMessage(), DEBUG_DEVELOPER);
            // On failure, still return tuple per contract with newfile=false.
            return [$contenthash, $filesize, false];
        }
    }

    /**
     * Add file from string content.
     *
     * @param string $content File content
     * @return array (contenthash, filesize, newfile)
     */
    public function add_file_from_string($content) {
        // Write to temp file first
        $tempfile = tempnam(sys_get_temp_dir(), 'moodle_');
        file_put_contents($tempfile, $content);
        $contenthash = sha1($content);
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
        $this->log_debug('get_local_path_from_hash called', [
            'contenthash' => substr($contenthash, 0, 8) . '...',
            'fetchifnotfound' => $fetchifnotfound,
        ]);

        // Check cache first
        $cachedpath = $this->cache->get($contenthash);
        if ($cachedpath && file_exists($cachedpath)) {
            $this->log_debug('Cache hit', ['contenthash' => substr($contenthash, 0, 8) . '...']);
            return $cachedpath;
        }

        if (!$fetchifnotfound) {
            $this->log_debug('Not in cache, fetch disabled', ['contenthash' => substr($contenthash, 0, 8) . '...']);
            return false;
        }

        // Download from Spaces
        $key = $this->get_s3_key_from_hash($contenthash);
        $localpath = $this->cache->get_cache_path($contenthash);

        $this->log_debug('Downloading from Spaces', [
            'contenthash' => substr($contenthash, 0, 8) . '...',
            'key' => $key,
        ]);

        try {
            $start = microtime(true);

            // Ensure directory exists
            $dir = dirname($localpath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            // Download file
            $this->client->get_object($this->config['bucket'], $key, $localpath);
            
            $duration = round((microtime(true) - $start) * 1000, 2);
            $this->log_debug('Download completed', [
                'contenthash' => substr($contenthash, 0, 8) . '...',
                'duration_ms' => $duration,
                'filesize' => filesize($localpath),
            ]);
            
            // Add to cache
            $this->cache->add($contenthash, $localpath);
            
            return $localpath;
        } catch (\Exception $e) {
            $this->log_debug('Download failed', [
                'contenthash' => substr($contenthash, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
            debugging('Failed to download file from DO Spaces: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Get S3 key path from contenthash (internal helper).
     *
     * @param string $contenthash Content hash
     * @return string S3 key path
     */
    protected function get_s3_key_from_hash($contenthash) {
        $l1 = substr($contenthash, 0, 2);
        $l2 = substr($contenthash, 2, 2);
        return "$l1/$l2/$contenthash";
    }

    /**
     * Get S3 key path from contenthash (internal helper).
     *
     * @param string $contenthash Content hash
     * @return string S3 key path
     */
    protected function get_s3_key_from_hash($contenthash) {
        $l1 = substr($contenthash, 0, 2);
        $l2 = substr($contenthash, 2, 2);
        return "$l1/$l2/$contenthash";
    }

    /**
     * Get remote storage path from contenthash.
     *
     * For DO Spaces, we can't return a streamable URL that PHP's file_get_contents()
     * can read directly (would need presigned URLs which are complex).
     * Instead, we fetch the file locally and return that path.
     *
     * @param string $contenthash Content hash
     * @return string Local path (fetched if needed)
     */
    public function get_remote_path_from_hash($contenthash) {
        // For remote storage, "remote path" doesn't make sense for direct access.
        // Fetch locally and return local path instead.
        return $this->get_local_path_from_hash($contenthash, true);
    }

    /**
     * Check if file exists in storage.
     *
     * @param string $contenthash Content hash
     * @return bool Exists
     */
    public function is_file_readable_remotely_by_hash($contenthash) {
        $key = $this->get_s3_key_from_hash($contenthash);
        
        $this->log_debug('is_file_readable_remotely_by_hash called', [
            'contenthash' => substr($contenthash, 0, 8) . '...',
            'key' => $key,
        ]);
        
        try {
            $start = microtime(true);
            $result = $this->client->head_object($this->config['bucket'], $key);
            $duration = round((microtime(true) - $start) * 1000, 2);
            
            $this->log_debug('HEAD request completed', [
                'contenthash' => substr($contenthash, 0, 8) . '...',
                'exists' => $result,
                'duration_ms' => $duration,
            ]);
            
            return $result;
        } catch (\Exception $e) {
            $this->log_debug('HEAD request failed', [
                'contenthash' => substr($contenthash, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
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
        $key = $this->get_s3_key_from_hash($contenthash);
        
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
     * Helper: Get file content by hash (do not override core signature).
     *
     * @param string $contenthash Content hash
     * @return string|false
     */
    public function get_content_by_hash($contenthash) {
        $path = $this->get_local_path_from_hash($contenthash, true);
        if ($path && file_exists($path)) {
            return file_get_contents($path);
        }
        return false;
    }

    /**
     * Ensure we always have a local path for a stored_file; override core so we can fetch from Spaces.
     *
     * Core implementation for local file systems may return a path without forcing a download.
     * For remote storage we need to guarantee the file exists locally for subsequent operations
     * (image info, mime detection, copying, etc.). Therefore we always pass fetch=true.
     *
     * @param \stored_file $file Stored file instance
     * @param bool $fetchifnotfound (ignored, we always fetch)
     * @return string|false Local path or false on failure
     */
    public function get_local_path_from_storedfile(\stored_file $file, $fetchifnotfound = false) {
        $contenthash = $file->get_contenthash();
        $this->log_debug('get_local_path_from_storedfile override', [
            'contenthash' => substr($contenthash, 0, 8) . '...'
        ]);
        $path = $this->get_local_path_from_hash($contenthash, true);
        if ($path) {
            $exists = file_exists($path);
            $readable = is_readable($path);
            $size = $exists ? filesize($path) : null;
            $this->log_debug('get_local_path_from_storedfile post-fetch check', [
                'contenthash' => substr($contenthash, 0, 8) . '...',
                'path' => $path,
                'exists' => $exists,
                'readable' => $readable,
                'size' => $size,
            ]);
            if ($exists && !$readable) {
                $this->log_debug('WARNING: File exists but is not readable', ['path' => $path]);
            }
        } else {
            $this->log_debug('get_local_path_from_storedfile failed to obtain path', [
                'contenthash' => substr($contenthash, 0, 8) . '...'
            ]);
        }
        return $path;
    }
    
    /**
     * Output the content of the specified stored file.
     *
     * Override core readfile() to ensure we always use local paths.
     * Core tries to use remote paths if file not locally cached, but readfile()
     * can't handle HTTPS URLs the way it expects.
     *
     * @param \stored_file $file The file to serve.
     * @return void
     * @throws \file_exception
     */
    public function readfile(\stored_file $file) {
        $this->log_debug('readfile called', [
            'contenthash' => substr($file->get_contenthash(), 0, 8) . '...',
            'filename' => $file->get_filename(),
        ]);

        // Always get local path with fetch=true to ensure file is downloaded.
        $path = $this->get_local_path_from_storedfile($file, true);
        
        if (!$path || !is_readable($path)) {
            $this->log_debug('readfile ERROR: path not readable', [
                'path' => $path,
                'filename' => $file->get_filename(),
            ]);
            throw new \file_exception('storedfilecannotreadfile', $file->get_filename());
        }

        $this->log_debug('readfile calling readfile_allow_large', [
            'path' => $path,
            'filesize' => $file->get_filesize(),
        ]);

        if (readfile_allow_large($path, $file->get_filesize()) === false) {
            $this->log_debug('readfile ERROR: readfile_allow_large failed', [
                'path' => $path,
            ]);
            throw new \file_exception('storedfilecannotreadfile', $file->get_filename());
        }

        $this->log_debug('readfile completed successfully');
    }

    /**
     * Copy content of stored_file to target pathname.
     *
     * @param \stored_file $file
     * @param string $target
     * @return bool
     */
    public function copy_content_from_storedfile(\stored_file $file, $target) {
        // Ensure local path available.
        $source = $this->get_local_path_from_storedfile($file, true);
        if (!is_readable($source)) {
            return false;
        }
        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return @copy($source, $target);
    }

    /**
     * Get a file handle for the specified stored file.
     *
     * Override to ensure we always use local paths, since fopen() doesn't work with
     * remote HTTPS URLs in the way core expects.
     *
     * @param \stored_file $file The file to get a handle for
     * @param int $type Type of file handle (FILE_HANDLE_FOPEN or FILE_HANDLE_GZOPEN)
     * @return resource File handle
     */
    public function get_content_file_handle(\stored_file $file, $type = \stored_file::FILE_HANDLE_FOPEN) {
        $this->log_debug('get_content_file_handle called', [
            'contenthash' => substr($file->get_contenthash(), 0, 8) . '...',
            'filename' => $file->get_filename(),
            'type' => $type,
        ]);

        // Always fetch local path for file handles since fopen/gzopen need local files.
        $path = $this->get_local_path_from_storedfile($file, true);
        
        if (!$path || !is_readable($path)) {
            $this->log_debug('get_content_file_handle failed: path not readable', [
                'path' => $path,
                'contenthash' => substr($file->get_contenthash(), 0, 8) . '...',
            ]);
            return false;
        }

        $this->log_debug('get_content_file_handle returning handle', [
            'path' => $path,
            'type' => $type,
        ]);

        return self::get_file_handle_for_path($path, $type);
    }

    /**
     * Get image information for a stored file.
     *
     * Override to add detailed logging for debugging course image issues.
     *
     * @param \stored_file $file The file to get image info for
     * @return array|false Image info array or false
     */
    public function get_imageinfo_from_storedfile(\stored_file $file) {
        $contenthash = $file->get_contenthash();
        $filename = $file->get_filename();
        
        $this->log_debug('get_imageinfo_from_storedfile called', [
            'contenthash' => substr($contenthash, 0, 8) . '...',
            'filename' => $filename,
            'filesize' => $file->get_filesize(),
        ]);

        // Check cache first (same as parent).
        $cache = \cache::make('core', 'file_imageinfo');
        $info = $cache->get($contenthash);
        if ($info !== false) {
            $this->log_debug('get_imageinfo_from_storedfile cache hit', [
                'contenthash' => substr($contenthash, 0, 8) . '...',
            ]);
            return $info;
        }

        // Get local path.
        $path = $this->get_local_path_from_storedfile($file, true);
        
        if (!$path || !file_exists($path)) {
            $this->log_debug('get_imageinfo_from_storedfile ERROR: path not found', [
                'contenthash' => substr($contenthash, 0, 8) . '...',
                'filename' => $filename,
                'path' => $path,
            ]);
            return false;
        }

        if (!is_readable($path)) {
            $this->log_debug('get_imageinfo_from_storedfile ERROR: path not readable', [
                'contenthash' => substr($contenthash, 0, 8) . '...',
                'filename' => $filename,
                'path' => $path,
                'perms' => substr(sprintf('%o', fileperms($path)), -4),
            ]);
            return false;
        }

        $this->log_debug('get_imageinfo_from_storedfile getting info from path', [
            'path' => $path,
        ]);

        $info = $this->get_imageinfo_from_path($path);
        
        if ($info === false) {
            $this->log_debug('get_imageinfo_from_storedfile WARNING: get_imageinfo_from_path returned false', [
                'path' => $path,
                'filename' => $filename,
            ]);
        } else {
            $cache->set($contenthash, $info);
            $this->log_debug('get_imageinfo_from_storedfile success', [
                'width' => $info['width'] ?? null,
                'height' => $info['height'] ?? null,
            ]);
        }

        return $info;
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
