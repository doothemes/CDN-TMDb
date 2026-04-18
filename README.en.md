# CDN Proxy — TMDB Images

> [🇪🇸 Español](README.md) · 🇬🇧 **English**

Reverse proxy with permanent storage for [The Movie Database](https://www.themoviedb.org/) images. Downloads images from `image.tmdb.org`, stores them on disk and serves them from your own domain.

After the first download, Apache serves the images as static files — PHP is not involved.

## Requirements

- PHP 8.1+
- Apache with `mod_rewrite` and `mod_expires`
- `curl` extension enabled

## Structure

```
cdn.dbmvs/
├── .htaccess       # Rewrite rules + cache + anti-hotlink
├── .env.example    # Configuration template
├── .env            # Environment variables (not committed)
├── .gitattributes  # Normalizes line endings and controls exports
├── .gitignore      # Excludes /t/, /logs/, .env and cron.lock
├── env.php         # Environment variables loader
├── helpers.php     # Shared functions between index.php and cron.php
├── index.php       # Proxy + administrative endpoints
├── cron.php        # Scheduled task for automatic cleanup
├── logs/           # Audit and error logs (auto-created)
│   ├── audit.log   # Log of sensitive operations (cleaner)
│   ├── audit.log.1 # Old rotations (up to LOG_KEEP_FILES)
│   ├── error.log   # cURL errors and upstream failures
│   └── error.log.1 # Old rotations
└── t/              # Stored images (auto-created)
    └── p/
        ├── w500/
        ├── w780/
        ├── original/
        └── ...
```

## Usage

Replace the TMDB host with your CDN:

```
# Before (direct to TMDB)
https://image.tmdb.org/t/p/w500/kqjL17yufvn9OVLyXYpvtyrFfak.jpg

# After (your CDN)
https://cdn.dbmvs.io/t/p/w500/kqjL17yufvn9OVLyXYpvtyrFfak.jpg
```

```html
<img src="https://cdn.dbmvs.io/t/p/w500/kqjL17yufvn9OVLyXYpvtyrFfak.jpg" alt="Poster">
```

### Request flow

```
1st request:
  Client → Apache (not on disk) → index.php → fetch from TMDB → save to /t/p/w500/ → respond

2nd request onwards:
  Client → Apache (file exists) → serves directly as static (PHP is not executed)
```

## .htaccess

The `.htaccess` file has three functional blocks:

### Rewrite (mod_rewrite)

Controls the main CDN flow. If the requested file already exists physically on disk, Apache serves it as static without touching PHP. Only when the file **does not exist** (first request for that image) does it route to `index.php` to download it from TMDB.

```apache
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

### Anti-hotlink

Blocks the use of images from unauthorized domains. **Disabled by default** (lines commented with `###`). When enabled, it works as follows:

1. If `Referer` is empty → allowed (direct access, apps, bots)
2. If `Referer` matches an allowed domain → allowed
3. If it does not match any → returns `403 Forbidden`

Conditions are AND: **all** must fail for blocking to apply. To add an allowed domain, add a line:

```apache
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?yourdomain\.com [NC]
```

### Browser cache (mod_expires)

Sets Apache-level caching for static files served without going through PHP. This complements the `Cache-Control` headers sent by `index.php` during the first download.

```apache
ExpiresByType image/jpeg "access plus 1 year"
ExpiresByType image/png "access plus 1 year"
ExpiresByType image/webp "access plus 1 year"
ExpiresByType image/svg+xml "access plus 1 year"
```

Cache of **1 year** for all image types handled by the CDN. It is safe because TMDB images are immutable — if the content changes, the filename hash also changes.

## Installation

1. Clone or download the repository to the domain's document root
2. Copy the configuration template:
   ```bash
   cp .env.example .env
   ```
3. Edit `.env` with production values:
   ```env
   API_SECRET=your-secret-key-here
   MAX_INACTIVE_DAYS=30
   ```
4. Verify Apache has `mod_rewrite` and `mod_expires` enabled
5. (Optional) Schedule `cron.php` in crontab for automatic cleanup

## Configuration (.env)

All configuration is managed from the `.env` file at the project root. The `env.php` file reads the variables and exposes them via the `env()` function.

| Variable | Description | Default |
|----------|-------------|---------|
| `API_SECRET` | Secret key for administrative endpoints (`get_stats`, `cleaner`) | *(empty)* |
| `MAX_INACTIVE_DAYS` | Max days without access before the cron deletes an image | `30` |
| `CORS_ORIGIN` | Value of the `Access-Control-Allow-Origin` header | `*` |
| `NEGATIVE_CACHE_TTL` | Seconds to remember a TMDB 404 to avoid repeating invalid requests | `3600` |
| `GOOGLEBOT_IPS` | Pool of Googlebot IPs (CSV) to rotate in `X-Forwarded-For` | *(9 hardcoded IPs)* |
| `LOG_MAX_SIZE_MB` | Max size in MB before automatically rotating a log | `5` |
| `LOG_KEEP_FILES` | Number of old rotations kept | `5` |

### Anti-hotlink

The `.htaccess` includes commented anti-hotlink rules. To enable them, uncomment the `###` lines and adjust the allowed domains:

```apache
RewriteCond %{HTTP_REFERER} !^$
RewriteCond %{HTTP_REFERER} !^https?://(www\.)?yourdomain\.com [NC]
RewriteRule \.(jpg|jpeg|png|webp|svg)$ - [F,NC,L]
```

## Administrative endpoints

All endpoints require the `X-Api-Key` header with the value of `API_SECRET`.

### GET /get_stats

Returns CDN storage statistics.

```bash
curl -H "X-Api-Key: your-secret-key" https://cdn.dbmvs.io/get_stats
```

Response:

```json
{
    "version": "1.1.0",
    "folders": 3,
    "files": 1240,
    "archival": 5,
    "size": {
        "bytes": 52428800,
        "human": "50 MB"
    }
}
```

| Field | Description |
|-------|-------------|
| `version` | Current deployed CDN version |
| `folders` | Total number of folders inside `/t/` |
| `files` | Total number of stored images |
| `archival` | Subset of `files` protected as archival (no longer in TMDB) |
| `size.bytes` | Disk space in bytes |
| `size.human` | Disk space in human-readable format |

### POST /cleaner

Runs cleanup of stored images. Supports two modes:

**Full cleanup** — deletes all images and folders:

```bash
curl -X POST \
  -H "X-Api-Key: your-secret-key" \
  -d '{"mode": "all"}' \
  https://cdn.dbmvs.io/cleaner
```

**Cleanup by age** — deletes files older than X days since download:

```bash
curl -X POST \
  -H "X-Api-Key: your-secret-key" \
  -d '{"mode": "older_than", "days": 30}' \
  https://cdn.dbmvs.io/cleaner
```

**Forced cleanup** — ignores archival protection and deletes **absolutely everything**:

```bash
curl -X POST \
  -H "X-Api-Key: your-secret-key" \
  -d '{"mode": "all", "force": true}' \
  https://cdn.dbmvs.io/cleaner
```

Response:

```json
{
    "mode": "older_than",
    "days": 30,
    "force": false,
    "deleted_files": 87,
    "preserved_archival": 5,
    "deleted_folders": 2
}
```

| Field | Description |
|-------|-------------|
| `mode` | Executed cleanup mode (`all` or `older_than`) |
| `days` | Age in days (only in `older_than` mode) |
| `force` | If `true`, ignores archival markers and deletes everything |
| `deleted_files` | Number of deleted images |
| `preserved_archival` | Images preserved by archival protection |
| `deleted_folders` | Number of deleted empty folders |

## CRON task (cron.php)

Automatic cleanup script executed from the command line. Deletes images that haven't been requested in a configurable period and removes orphaned empty folders.

### Configuration

The inactivity threshold is configured in `.env`:

```env
MAX_INACTIVE_DAYS=30
```

The script uses `fileatime()` (OS access time) to determine when an image was last requested. If the filesystem doesn't support atime, it falls back to `filemtime()` (download date).

### Manual execution

```bash
php /path/to/cdn.dbmvs/cron.php
```

### Schedule in crontab

```bash
# Run every day at 3:00 AM
0 3 * * * php /path/to/cdn.dbmvs/cron.php >> /var/log/cdn-cleanup.log 2>&1

# Run every Monday at 2:00 AM
0 2 * * 1 php /path/to/cdn.dbmvs/cron.php >> /var/log/cdn-cleanup.log 2>&1
```

### Report output

Each execution generates a report with timestamp for logs:

```
[03:00:01] Starting CDN cleanup...
[03:00:01] Deleting images not accessed in the last 30 days.
[03:00:01] Threshold date: 2026-03-18 03:00:01
[03:00:01] --------------------------------------------------
[03:00:01] --------------------------------------------------
[03:00:01] Cleanup completed: 2026-04-17 03:00:01
[03:00:01]   Deleted files:    87 (124.5 MB freed)
[03:00:01]   Deleted folders:  2
[03:00:01]   Kept files:       953
```

| Field | Description |
|-------|-------------|
| Deleted files | Images that exceeded the inactivity threshold (with freed space) |
| Deleted folders | Empty directories orphaned after cleanup |
| Kept files | Images still within the activity period |

### Security

- Can only be executed from CLI (`php_sapi_name() === 'cli'`). If accessed via web, returns `403 Forbidden`
- No API key required because server access already implies authorization

## Googlebot identity

To avoid rate-limiting and blocks from the TMDB CDN, each request to `image.tmdb.org` is sent simulating a Google crawler. This strategy combines:

- **Googlebot User-Agent**: `Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)`
- **Pool of 9 Googlebot IPs** (`66.249.66.x`) rotated randomly on each request
- **`X-Forwarded-For` header** with the selected IP — respected by many CDNs and reverse proxies as the origin IP

TMDB, like other services, gives preferential treatment to Google crawlers (whitelisted) to allow indexing. Leveraging that identity drastically reduces the likelihood of being rate-limited during massive image downloads.

## Archival protection

TMDB periodically removes images from its catalog (distribution changes, pulled content, closed studio accounts). Once removed, **they cannot be re-downloaded**. The CDN automatically detects these cases and protects them from deletion.

### How it works

Before deleting an old image, the cron performs a `HEAD` request to TMDB:

| TMDB response | Action |
|---------------|--------|
| `200 OK` | Safe to delete — will be re-downloaded if requested |
| `404 Not Found` / `410 Gone` | Creates `.archival` marker and **preserves forever** |
| Timeout / `5xx` / `429` | Cannot verify — preserve out of caution, retry next round |

Each archival image has a sibling marker: `abc.jpg` + `abc.jpg.archival`. The marker is an empty file flagging "never delete".

### Performance

The HEAD is only triggered **during cleanup operations** (nightly cron). It **never** affects image serving. Also, once marked as archival, subsequent cron runs skip it immediately without HEAD again.

### Force total deletion

If you need to wipe the CDN completely including archival (e.g., server migration), use `force: true`:

```bash
curl -X POST \
  -H "X-Api-Key: your-secret-key" \
  -d '{"mode": "all", "force": true}' \
  https://cdn.dbmvs.io/cleaner
```

Without `force`, both `/cleaner` and `cron.php` always respect archival markers.

### Cron report

With archival protection active, the cron report shows more detail:

```
[03:00:01] Cleanup completed: 2026-04-17 03:00:01
[03:00:01]   Deleted files:    87 (124.5 MB freed)
[03:00:01]   Archived files:   3 (permanently protected)
[03:00:01]   Uncertain files:  1 (TMDB did not respond, retry)
[03:00:01]   Deleted folders:  2
[03:00:01]   Kept files:       953
```

## Logs

The CDN maintains two separate logs in `/logs/`:

| File | Content |
|------|---------|
| `audit.log` | Executions of `POST /cleaner` with IP, mode and result |
| `error.log` | cURL failures when downloading from TMDB (timeouts, DNS, etc.) |

### Automatic rotation

When a log reaches `LOG_MAX_SIZE_MB` (default 5 MB), it rotates automatically:

```
audit.log      ← active file
audit.log.1    ← most recent rotation
audit.log.2
...
audit.log.5    ← oldest
```

On the next rotation, `audit.log.5` is deleted, the rest shift, and the active file becomes `.1`. The rotation limit is configured with `LOG_KEEP_FILES`.

With defaults (5 MB × 5 rotations), the maximum space per log is **25 MB**.

## Security

- **Path validation**: strict regex that only accepts `/t/p/{size}/{hash}.{ext}` — prevents path traversal and SSRF
- **Limited extensions**: derived from `MIME_TYPES` in `helpers.php` (single source)
- **Double content validation**: `Content-Type` header + binary inspection with `finfo`
- **Atomic writing**: `file_put_contents` to `.tmp` + `rename()` avoids partial files
- **Negative cache**: TMDB 404s are remembered to prevent hash enumeration attacks
- **Lock file in cron**: prevents concurrent cleaner executions
- **Audit log**: every `/cleaner` execution is logged with IP, mode and result
- **Anti-hotlink**: domain-level protection configurable via `.htaccess`
- **Protected API**: admin endpoints require `X-Api-Key` validated with `hash_equals()` (timing-attack resistant)
- **Configurable CORS**: `Access-Control-Allow-Origin` via `.env`
- **Hardening**: `X-Content-Type-Options: nosniff` and `Referrer-Policy`

## Cache headers

| Header | Value | Purpose |
|--------|-------|---------|
| `Cache-Control` | `public, max-age=2592000, immutable` | Browser cache for 30 days |
| `ETag` | File MD5 | Conditional validation (304 Not Modified) |
| `Last-Modified` | Download date | Alternative conditional validation |
| `X-Cache` | `HIT` | Indicates the image was served from the CDN |
| `Access-Control-Allow-Origin` | `*` | Enables cross-origin usage |

## Why a self-hosted CDN?

Deploying your own CDN for TMDB images instead of consuming them directly from `image.tmdb.org` offers significant advantages:

### 1. Control over availability
TMDB can apply rate-limiting, change its URLs, or restrict access by region. A self-hosted CDN decouples your platform from those decisions — your images are already on your infrastructure.

### 2. Performance and browser cache
Serving from `cdn.yourdomain.com` allows aggressive headers (`Cache-Control: immutable`, 1 year) and shared cache across your own sites. Each domain has its own cache bucket in the browser — using your CDN unifies requests.

### 3. Origin independence
If TMDB goes down, undergoes maintenance, or blocks your IP, your platform keeps working because the images are already on disk.

### 4. Anti-hotlinking and usage control
You can restrict which domains use your images via `Referer`. Not possible with the public TMDB CDN.

### 5. Geographical block evasion
Some countries or ISPs block `image.tmdb.org`. Serving from your domain bypasses those blocks.

### 6. Bandwidth savings across multiple sites
When you have several sites (dbmvs.com, wovie.co, doothemes.com) consuming the same images, a centralized CDN prevents each site from downloading the same image from TMDB. A single download serves them all.

### 7. TMDB terms compliance
TMDB has request limits per API key. With the CDN, an image is downloaded **only once** no matter how many times it's requested afterwards. This drastically reduces consumed quota.

### 8. Logs and analytics
You have full visibility of which images are requested, from which domains, and how often. Impossible with a third-party CDN.

### 9. Future optimization
With images on your own disk, you can add processing: WebP/AVIF conversion, dynamic resizing, aggressive compression, watermarks — without touching the origin.

### 10. Predictable costs
The TMDB CDN is free but not guaranteed. A self-hosted server has fixed, predictable costs, with no surprises from exceeded quotas or changing terms.

### 11. Policy-change resilience
TMDB could, at any time, require authentication for images, charge for traffic, or restrict commercial use. Having the images already downloaded protects your business from those changes.
