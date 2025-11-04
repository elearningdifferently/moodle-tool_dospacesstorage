<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * LRU cache manager for local file caching.
 *
 * @package     tool_dospacesstorage
 * @copyright   2025 eLearning Differently
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_dospacesstorage;

defined('MOODLE_INTERNAL') || die();

/**
 * Local file cache manager with LRU eviction.
 */
class cache_manager {

    /** @var string Cache directory path */
    protected $cachepath;

    /** @var int Max cache size in bytes */
    protected $maxsize;

    /** @var string Cache index file path */
    protected $indexfile;

    /**
     * Constructor.
     *
     * @param string $cachepath Cache directory
     * @param int $maxsize Max cache size in bytes
     */
    public function __construct($cachepath, $maxsize) {
        $this->cachepath = rtrim($cachepath, '/');
        $this->maxsize = $maxsize;
        $this->indexfile = $this->cachepath . '/.cacheindex';

        // Ensure cache directory exists
        if (!is_dir($this->cachepath)) {
            mkdir($this->cachepath, 0777, true);
        }
    }

    /**
     * Get cache path for contenthash.
     *
     * @param string $contenthash Content hash
     * @return string Local path
     */
    public function get_cache_path($contenthash) {
        // Mirror Moodle's directory structure
        $l1 = substr($contenthash, 0, 2);
        $l2 = substr($contenthash, 2, 2);
        return $this->cachepath . "/$l1/$l2/$contenthash";
    }

    /**
     * Get cached file path if exists.
     *
     * @param string $contenthash Content hash
     * @return string|bool Path if exists, false otherwise
     */
    public function get($contenthash) {
        $path = $this->get_cache_path($contenthash);
        
        if (file_exists($path)) {
            // Update access time for LRU
            touch($path);
            return $path;
        }
        
        return false;
    }

    /**
     * Add file to cache.
     *
     * @param string $contenthash Content hash
     * @param string $sourcepath Source file path
     * @return bool Success
     */
    public function add($contenthash, $sourcepath) {
        $cachepath = $this->get_cache_path($contenthash);
        
        // Ensure directory exists
        $dir = dirname($cachepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // Copy file to cache
        if ($sourcepath !== $cachepath) {
            if (!copy($sourcepath, $cachepath)) {
                return false;
            }
        }

        // Check cache size and evict if needed
        $this->evict_if_needed();

        return true;
    }

    /**
     * Remove file from cache.
     *
     * @param string $contenthash Content hash
     * @return bool Success
     */
    public function remove($contenthash) {
        $path = $this->get_cache_path($contenthash);
        
        if (file_exists($path)) {
            return @unlink($path);
        }
        
        return true;
    }

    /**
     * Get current cache size in bytes.
     *
     * @return int Size in bytes
     */
    public function get_cache_size() {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cachepath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.cacheindex') {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Evict least recently used files if cache is over limit.
     */
    protected function evict_if_needed() {
        $currentsize = $this->get_cache_size();
        
        if ($currentsize <= $this->maxsize) {
            return; // Cache is within limits
        }

        // Get all cached files with access times
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cachepath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.cacheindex') {
                $files[] = [
                    'path' => $file->getPathname(),
                    'atime' => $file->getATime(),
                    'size' => $file->getSize(),
                ];
            }
        }

        // Sort by access time (oldest first)
        usort($files, function($a, $b) {
            return $a['atime'] <=> $b['atime'];
        });

        // Remove files until we're under limit
        $targetsize = $this->maxsize * 0.8; // Remove down to 80% of max
        foreach ($files as $file) {
            if ($currentsize <= $targetsize) {
                break;
            }

            if (@unlink($file['path'])) {
                $currentsize -= $file['size'];
            }
        }

        // Clean up empty directories
        $this->cleanup_empty_dirs();
    }

    /**
     * Remove empty directories from cache.
     */
    protected function cleanup_empty_dirs() {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cachepath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $dir) {
            if ($dir->isDir()) {
                @rmdir($dir->getPathname());
            }
        }
    }

    /**
     * Clear entire cache.
     *
     * @return bool Success
     */
    public function clear() {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cachepath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.cacheindex') {
                @unlink($file->getPathname());
            } else if ($file->isDir()) {
                @rmdir($file->getPathname());
            }
        }

        return true;
    }
}
