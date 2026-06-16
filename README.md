# ImgPress for WordPress

A WordPress plugin that compresses images, PDFs, audio, and video files through the [ImgPress](https://github.com/binjuhor/imgpress) API — automatically on upload or manually via the media library.

## Features

- **Auto-compress on upload** — files are compressed before the attachment post is created, so WordPress stores the optimized version from the start
- **Manual & bulk compression** — compress existing media one at a time from the media library, or process your entire library in bulk
- **Multi-format support** — images (JPEG, PNG, WebP, HEIC, AVIF, GIF), PDFs, audio (MP3/WAV/FLAC → M4A), and video (MP4/MOV/AVI → MP4)
- **Configurable output** — control quality, output format (WebP, AVIF, JPEG, or auto), and max image width
- **Media library integration** — an "⚡ ImgPress" column shows compression stats (reduction %, before/after size, date) for every attachment
- **Bulk compress dashboard** — progress bar, per-file results table, and aggregate stats (total saved, average reduction)
- **Cloudflare R2 storage** — offload media to R2 with one-click bulk uploads, optional automatic push on compress, custom domain support, and optional local file deletion

## Requirements

- WordPress 6.0+
- PHP 8.1+
- Access to an [ImgPress API](https://github.com/binjuhor/imgpress) instance
- (Optional) Cloudflare account with R2 enabled for media storage

## Installation

1. Download or clone this repository into `wp-content/plugins/imgpress-wp`
2. Activate the plugin from **Plugins → Installed Plugins**
3. Go to **Settings → ImgPress** and enter your API URL

## Configuration

All settings live under **Settings → ImgPress**.

### Compression Settings

| Setting | Default | Description |
|---|---|---|
| API URL | `http://imgpress.binjuhor.com` | Base URL of your ImgPress API (no trailing slash) |
| Request timeout | `120s` | Max seconds to wait for the API per file |
| Auto-compress | Enabled | Compress files automatically on upload |
| Quality | `80` | Compression quality (1–100) |
| Output format | `webp` | `webp`, `avif`, `jpeg`, or `auto` |
| Max width | `1600px` | Images wider than this are resized |
| File types | All | Toggle image, PDF, audio, and/or video compression |

Use the **Test Connection** button to verify the API is reachable before saving.

### R2 Storage (Optional)

| Setting | Default | Description |
|---|---|---|
| Enable R2 | Disabled | Toggle Cloudflare R2 storage integration |
| Account ID | — | Cloudflare R2 Account ID (found in R2 dashboard) |
| Access Key ID | — | S3 Access Key ID from R2 API token |
| Secret Access Key | — | S3 Secret Access Key from R2 API token |
| Bucket Name | — | Name of your R2 bucket |
| Custom Domain | — | Optional custom domain for public access (host only) |
| Auto-push on compress | Disabled | Automatically upload compressed files to R2 |
| Auto-push on upload | Disabled | Automatically upload files on initial upload |
| Delete local files | Disabled | ⚠️ Permanently delete local files after R2 sync |
| Rewrite content URLs | Disabled | Rewrite hardcoded image URLs in posts to R2 URLs |

Use the **Test R2 Connection** button to verify your R2 credentials are valid.

## Usage

### Automatic compression

With auto-compress enabled, every uploaded file is sent to the API before WordPress creates the attachment. No action required.

### Single file compression

In the media library list view, find the **⚡ ImgPress** column. Files that haven't been compressed show a **Compress** button — click it to compress that file in place.

### Bulk compression

Go to **Media → Bulk Compress**. The page shows how many files are pending, then lets you start a batch run that processes each file sequentially and displays a live progress bar and results table.

### R2 Storage

To use Cloudflare R2 storage:

1. **Create an R2 bucket** in your Cloudflare dashboard
2. **Create an API token** with R2 read/write permissions and copy the credentials
3. **Configure R2 in ImgPress settings**:
   - Enable R2
   - Enter your Account ID, Access Key ID, and Secret Access Key
   - Specify your bucket name
   - (Optional) Add a custom domain for public access
   - Test the connection with the **Test R2 Connection** button
4. **Push files to R2** manually:
   - In the media library, use the **Push to R2** button on individual files
   - Or go to **Media → Bulk Offload to R2** to upload your entire library at once
5. **(Optional) Enable automatic sync**:
   - Check "Auto-push compressed files to R2" to upload automatically after compression
   - Check "Auto-push uncompressed files on upload" to upload new files immediately
   - Check "Delete local files after uploading" to save server space (⚠️ ensure you have backups!)
   - Check "Rewrite content URLs" to automatically update post content with R2 URLs

## Data stored

For each compressed attachment the plugin saves the following post meta:

| Key | Value |
|---|---|
| `_imgpress_original_size` | Original file size in bytes |
| `_imgpress_compressed_size` | Compressed file size in bytes |
| `_imgpress_ratio` | Reduction percentage |
| `_imgpress_compressed_at` | Unix timestamp of compression |
| `_imgpress_mime_out` | Output MIME type |

## File structure

```
imgpress-wp/
├── imgpress-wp.php          # Bootstrap, autoloader, activation hook
├── includes/
│   ├── class-settings.php       # Admin settings page & option getters
│   ├── class-api-client.php     # HTTP client for the ImgPress API
│   ├── class-compressor.php     # Core compress-and-replace logic
│   ├── class-auto-compress.php  # Hooks into wp_handle_upload
│   ├── class-media-columns.php  # Media library column & single-compress AJAX
│   ├── class-bulk-compress.php  # Bulk compress page & AJAX handlers
│   ├── class-r2-client.php      # S3-compatible API client for Cloudflare R2
│   ├── class-r2-uploader.php    # R2 upload orchestration & meta tracking
│   ├── class-r2-bulk.php        # Bulk offload page & AJAX handlers
│   └── class-r2-url-rewriter.php # Rewrites post content URLs to R2 URLs
├── admin/
│   ├── page-settings.php        # Settings page template
│   ├── page-bulk.php            # Bulk compress page template
│   └── page-r2-bulk.php         # Bulk R2 offload page template
└── assets/
    ├── admin.js                 # jQuery for AJAX actions and progress UI
    └── admin.css                # Admin styles
```

## License

MIT — see [LICENSE](LICENSE) for details.

## Author

[Hoang Kiem](https://github.com/binjuhor)
