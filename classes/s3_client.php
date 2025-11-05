<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Lightweight S3 client for DigitalOcean Spaces.
 *
 * @package     tool_dospacesstorage
 * @copyright   2025 eLearning Differently
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_dospacesstorage;

defined('MOODLE_INTERNAL') || die();

/**
 * Simple S3-compatible client for DO Spaces.
 * 
 * Uses AWS Signature Version 4 for authentication.
 */
class s3_client {

    /** @var string Access key */
    protected $key;

    /** @var string Secret key */
    protected $secret;

    /** @var string Region */
    protected $region;

    /** @var string Endpoint URL */
    protected $endpoint;

    /** @var string CDN endpoint URL (optional) */
    protected $cdnendpoint;

    /**
     * Constructor.
     *
     * @param string $key Access key
     * @param string $secret Secret key
     * @param string $region Region (e.g., 'nyc3')
     * @param string $endpoint Endpoint URL
     * @param string $cdnendpoint CDN endpoint URL (optional)
     */
    public function __construct($key, $secret, $region, $endpoint, $cdnendpoint = '') {
        $this->key = $key;
        $this->secret = $secret;
        $this->region = $region;
        $this->endpoint = rtrim($endpoint, '/');
        $this->cdnendpoint = $cdnendpoint ? rtrim($cdnendpoint, '/') : '';
    }

    /**
     * Upload file to bucket.
     *
     * @param string $bucket Bucket name
     * @param string $key Object key/path
     * @param string $filepath Local file path
     * @return bool Success
     * @throws \Exception On error
     */
    public function put_object($bucket, $key, $filepath) {
        $url = $this->get_url($bucket, $key);
        $filesize = filesize($filepath);
        $filehash = base64_encode(md5_file($filepath, true));
        
        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => $filesize,
            'Content-MD5' => $filehash,
            'x-amz-content-sha256' => hash_file('sha256', $filepath),
        ];

        $signature = $this->sign_request('PUT', $bucket, $key, $headers);
        $headers['Authorization'] = $signature;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => $this->format_headers($headers),
            CURLOPT_INFILE => fopen($filepath, 'r'),
            CURLOPT_INFILESIZE => $filesize,
            CURLOPT_UPLOAD => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpcode < 200 || $httpcode >= 300) {
            throw new \Exception("S3 PUT failed (HTTP $httpcode): $error - $response");
        }

        return true;
    }

    /**
     * Download file from bucket.
     *
     * @param string $bucket Bucket name
     * @param string $key Object key/path
     * @param string $filepath Local file path to save to
     * @return bool Success
     * @throws \Exception On error
     */
    public function get_object($bucket, $key, $filepath) {
        $fp = fopen($filepath, 'w');
        if (!$fp) {
            throw new \Exception("Cannot open file for writing: $filepath");
        }

        // Always use authenticated endpoint (not CDN) for downloads
        // CDN can be enabled later once bucket permissions are confirmed
        $url = $this->get_url($bucket, $key);
        
        $headers = [
            'x-amz-content-sha256' => hash('sha256', ''),
        ];
        $signature = $this->sign_request('GET', $bucket, $key, $headers);
        $headers['Authorization'] = $signature;
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $this->format_headers($headers),
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $effectiveurl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        fclose($fp);

        if ($httpcode < 200 || $httpcode >= 300) {
            @unlink($filepath);
            throw new \Exception("S3 GET failed (HTTP $httpcode) from $effectiveurl: $error");
        }

        return true;
    }

    /**
     * Check if object exists.
     *
     * @param string $bucket Bucket name
     * @param string $key Object key/path
     * @return bool Exists
     */
    public function head_object($bucket, $key) {
        $url = $this->get_url($bucket, $key);
        
        $headers = [
            'x-amz-content-sha256' => hash('sha256', ''),
        ];

        $signature = $this->sign_request('HEAD', $bucket, $key, $headers);
        $headers['Authorization'] = $signature;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'HEAD',
            CURLOPT_HTTPHEADER => $this->format_headers($headers),
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpcode >= 200 && $httpcode < 300);
    }

    /**
     * Delete object from bucket.
     *
     * @param string $bucket Bucket name
     * @param string $key Object key/path
     * @return bool Success
     * @throws \Exception On error
     */
    public function delete_object($bucket, $key) {
        $url = $this->get_url($bucket, $key);
        
        $headers = [
            'x-amz-content-sha256' => hash('sha256', ''),
        ];

        $signature = $this->sign_request('DELETE', $bucket, $key, $headers);
        $headers['Authorization'] = $signature;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => $this->format_headers($headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpcode < 200 || $httpcode >= 300) {
            throw new \Exception("S3 DELETE failed (HTTP $httpcode): $error - $response");
        }

        return true;
    }

    /**
     * Get full URL for object.
     *
     * @param string $bucket Bucket name
     * @param string $key Object key
     * @return string URL
     */
    protected function get_url($bucket, $key) {
        return $this->endpoint . '/' . $bucket . '/' . ltrim($key, '/');
    }

    /**
     * Sign request using AWS Signature Version 4.
     *
     * @param string $method HTTP method
     * @param string $bucket Bucket name
     * @param string $key Object key
     * @param array $headers Request headers
     * @return string Authorization header value
     */
    protected function sign_request($method, $bucket, $key, &$headers) {
        $datetime = gmdate('Ymd\THis\Z');
        $date = substr($datetime, 0, 8);
        
        $headers['Host'] = parse_url($this->endpoint, PHP_URL_HOST);
        $headers['x-amz-date'] = $datetime;

        // Canonical request
        $canonicaluri = '/' . $bucket . '/' . ltrim($key, '/');
        $canonicalquerystring = '';
        $canonicalheaders = '';
        $signedheaders = '';
        
        ksort($headers);
        foreach ($headers as $name => $value) {
            $lowerheader = strtolower($name);
            $canonicalheaders .= $lowerheader . ':' . trim($value) . "\n";
            $signedheaders .= $lowerheader . ';';
        }
        $signedheaders = rtrim($signedheaders, ';');

        $payloadhash = $headers['x-amz-content-sha256'] ?? hash('sha256', '');
        
        $canonicalrequest = implode("\n", [
            $method,
            $canonicaluri,
            $canonicalquerystring,
            $canonicalheaders,
            $signedheaders,
            $payloadhash
        ]);

        // String to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialscope = $date . '/' . $this->region . '/s3/aws4_request';
        $stringtosign = implode("\n", [
            $algorithm,
            $datetime,
            $credentialscope,
            hash('sha256', $canonicalrequest)
        ]);

        // Signing key
        $kdate = hash_hmac('sha256', $date, 'AWS4' . $this->secret, true);
        $kregion = hash_hmac('sha256', $this->region, $kdate, true);
        $kservice = hash_hmac('sha256', 's3', $kregion, true);
        $ksigning = hash_hmac('sha256', 'aws4_request', $kservice, true);

        // Signature
        $signature = hash_hmac('sha256', $stringtosign, $ksigning);

        // Authorization header
        return $algorithm . ' ' .
            'Credential=' . $this->key . '/' . $credentialscope . ', ' .
            'SignedHeaders=' . $signedheaders . ', ' .
            'Signature=' . $signature;
    }

    /**
     * Format headers for cURL.
     *
     * @param array $headers Associative array of headers
     * @return array Formatted headers
     */
    protected function format_headers($headers) {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }
        return $formatted;
    }
}
