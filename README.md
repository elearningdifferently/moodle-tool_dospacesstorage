# Moodle DigitalOcean Spaces Storage Plugin

A lightweight Moodle admin tool plugin that replaces local file storage with DigitalOcean Spaces object storage. Designed for cloud deployments with ephemeral disks (like DigitalOcean App Platform).

## Features

- **Direct S3-Compatible Storage**: All Moodle files stored in DigitalOcean Spaces
- **Lazy Loading Cache**: Files cached locally on-demand for performance
- **LRU Cache Management**: Automatic cache eviction when disk space is limited
- **Config-Driven**: Configure entirely via `config.php` for automated deployments
- **No Composer Required**: AWS SDK vendored directly in plugin
- **Zero Admin UI**: Works immediately after installation with proper config

## Requirements

- Moodle 4.3 or higher
- PHP 8.2 or higher
- DigitalOcean Spaces account with:
  - Spaces access key
  - Spaces secret key
  - A created Space (bucket)
  - Space endpoint URL

## Installation

### 1. Add Plugin to Moodle

**As Git Submodule (Recommended):**
```bash
cd /path/to/moodle
git submodule add https://github.com/elearningdifferently/moodle-tool_dospacesstorage.git admin/tool/dospacesstorage
git submodule update --init --recursive
```

**Or Manual Installation:**
```bash
cd /path/to/moodle/admin/tool/
git clone https://github.com/elearningdifferently/moodle-tool_dospacesstorage.git dospacesstorage
```

### 2. Create DigitalOcean Space

1. Log in to DigitalOcean control panel
2. Create a new Space:
   - Choose a region (e.g., `nyc3`, `lon1`)
   - Choose a unique name for your bucket
   - Set file listing to "Restricted" (recommended)
3. Generate Spaces access keys:
   - Go to API → Spaces Keys
   - Generate new key pair
   - Save the key and secret securely

### 3. Configure Environment Variables

Add these to your DigitalOcean App Platform environment variables (or `.env` for local dev):

```
DO_SPACES_KEY=your_spaces_access_key
DO_SPACES_SECRET=your_spaces_secret_key
DO_SPACES_BUCKET=your-bucket-name
DO_SPACES_REGION=nyc3
DO_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com
```

**Region and Endpoint Examples:**
- NYC3: `nyc3` / `https://nyc3.digitaloceanspaces.com`
- London: `lon1` / `https://lon1.digitaloceanspaces.com`
- Singapore: `sgp1` / `https://sgp1.digitaloceanspaces.com`

### 4. Update config.php

Add this configuration to your `config.php` (after the database config, before `require_once(__DIR__ . '/lib/setup.php');`):

```php
// DigitalOcean Spaces Storage Configuration
$CFG->alternative_file_system_class = '\\tool_dospacesstorage\\file_system';
$CFG->dospacesstorage = [
    'key' => getenv('DO_SPACES_KEY'),
    'secret' => getenv('DO_SPACES_SECRET'),
    'bucket' => getenv('DO_SPACES_BUCKET'),
    'region' => getenv('DO_SPACES_REGION') ?: 'nyc3',
    'endpoint' => getenv('DO_SPACES_ENDPOINT') ?: 'https://nyc3.digitaloceanspaces.com',
    // Optional: Local cache settings
    'cache_path' => '/tmp/moodledata/spacescache', // Cache directory (ephemeral OK)
    'cache_max_size' => 1073741824, // Max cache size in bytes (1GB default)
];
```

### 5. Install Plugin via Moodle

1. Log in to Moodle as administrator
2. Navigate to **Site Administration → Notifications**
3. Follow the prompts to install the plugin
4. No additional configuration needed - it's all in `config.php`!

## How It Works

### File Storage Flow

1. **Upload**: Files are uploaded directly to DigitalOcean Spaces
2. **Access**: First access downloads file to local cache (`cache_path`)
3. **Serving**: Subsequent requests serve from local cache (fast)
4. **Cache Management**: LRU eviction when cache exceeds `cache_max_size`
5. **Redeploy**: Cache is rebuilt automatically on new deploys (ephemeral disk)

### Performance

- **First access**: ~100-300ms (download from Spaces)
- **Cached access**: <5ms (local filesystem)
- **Uploads**: Direct to Spaces, no local copy needed
- **Memory**: ~50MB for S3 client + file buffers

### Ephemeral Disk Handling

Perfect for DigitalOcean App Platform:
- Cache stored in `/tmp` (ephemeral, cleared on redeploy)
- All permanent files in Spaces (persistent)
- On redeploy: Cache starts empty, rebuilds as files are accessed
- No data loss: Source of truth is always Spaces

## Configuration Options

### Required Settings

| Setting | Description | Example |
|---------|-------------|---------|
| `key` | DigitalOcean Spaces access key | `DO00ABC...` |
| `secret` | DigitalOcean Spaces secret key | `secret123...` |
| `bucket` | Space name (bucket) | `moodle-files` |
| `region` | Space region code | `nyc3` |
| `endpoint` | Space endpoint URL | `https://nyc3.digitaloceanspaces.com` |

### Optional Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `cache_path` | `/tmp/moodledata/spacescache` | Local cache directory |
| `cache_max_size` | `1073741824` (1GB) | Maximum cache size in bytes |
| `use_path_style` | `false` | Use path-style URLs (rarely needed) |

## Troubleshooting

### Files Not Uploading

**Check:**
1. Spaces credentials are correct (test with DO CLI or web UI)
2. Space bucket exists and is accessible
3. Moodle's PHP error logs for S3 errors
4. Network connectivity from server to Spaces endpoint

**Debug in config.php:**
```php
$CFG->debug = E_ALL;
$CFG->debugdisplay = 1; // Only on dev/staging!
```

### Files Not Downloading

**Check:**
1. Cache directory (`cache_path`) is writable by web server
2. Sufficient disk space for cache
3. File exists in Space (check DO control panel)

**Test cache:**
```bash
ls -lah /tmp/moodledata/spacescache
```

### Performance Issues

**Optimize:**
1. Increase `cache_max_size` if disk allows
2. Use Space in same region as App Platform
3. Enable CDN in front of Space for public files
4. Check network latency between app and Space

## Upgrading Moodle

This plugin is compatible with Moodle's upgrade process:

1. **Before Upgrade:**
   - Backup your Space bucket (optional, but recommended)
   - Note your current config.php settings

2. **During Upgrade:**
   - Plugin should work seamlessly
   - No file migration needed (files already in Spaces)

3. **After Upgrade:**
   - Test file uploads and downloads
   - Check Site Administration → Notifications for any plugin updates

## Uninstallation

**⚠️ WARNING**: Uninstalling this plugin will make all files in Spaces inaccessible to Moodle unless you migrate them back to local storage first.

### To Uninstall:

1. **Migrate files back to local storage** (if needed):
   - This plugin doesn't provide a migration tool
   - You'll need to manually copy files from Spaces to `$CFG->dataroot/filedir`

2. **Remove plugin:**
   ```bash
   rm -rf admin/tool/dospacesstorage
   ```

3. **Update config.php:**
   ```php
   // Remove or comment out:
   // $CFG->alternative_file_system_class = '\\tool_dospacesstorage\\file_system';
   // $CFG->dospacesstorage = [...];
   ```

4. **Clear caches:**
   - Site Administration → Development → Purge all caches

## Development

### Project Structure

```
admin/tool/dospacesstorage/
├── classes/
│   ├── file_system.php          # Main file system implementation
│   ├── s3_client.php            # Lightweight S3 client wrapper
│   └── cache_manager.php        # LRU cache management
├── lib/
│   └── aws-sdk/                 # Vendored AWS SDK for PHP (S3 only)
├── lang/
│   └── en/
│       └── tool_dospacesstorage.php
├── version.php
├── README.md
└── LICENSE
```

### Testing Locally

1. Install Moodle locally with this plugin
2. Use MinIO or LocalStack to simulate S3/Spaces
3. Configure `config.php` to point to local S3 endpoint
4. Test file upload, download, and cache eviction

### Contributing

Pull requests welcome! Please:
- Follow Moodle coding style
- Add PHPUnit tests for new features
- Update README for configuration changes

## License

GNU GPL v3 or later

## Credits

Developed by [eLearning Differently](https://github.com/elearningdifferently)

Inspired by [tool_objectfs](https://github.com/catalyst/moodle-tool_objectfs) by Catalyst IT.

## Support

- GitHub Issues: https://github.com/elearningdifferently/moodle-tool_dospacesstorage/issues
- Moodle Forums: https://moodle.org/plugins/tool_dospacesstorage

---

**Last Updated:** 2025-11-04
