# ImgPress for WordPress

A WordPress plugin that compresses images, PDFs, audio, and video files through the [ImgPress](https://github.com/binjuhor/imgpress) API — automatically on upload or manually via the media library.

## Features

- **Auto-compress on upload** — files are compressed before the attachment post is created, so WordPress stores the optimized version from the start
- **Manual & bulk compression** — compress existing media one at a time from the media library, or process your entire library in bulk
- **Multi-format support** — images (JPEG, PNG, WebP, HEIC, AVIF, GIF), PDFs, audio (MP3/WAV/FLAC → M4A), and video (MP4/MOV/AVI → MP4)
- **Configurable output** — control quality, output format (WebP, AVIF, JPEG, or auto), and max image width
- **Media library integration** — an "⚡ ImgPress" column shows compression stats (reduction %, before/after size, date) for every attachment
- **Bulk compress dashboard** — progress bar, per-file results table, and aggregate stats (total saved, average reduction)

## Requirements

- WordPress 6.0+
- PHP 8.1+
- Access to an [ImgPress API](https://github.com/binjuhor/imgpress) instance

## Installation

1. Download or clone this repository into `wp-content/plugins/imgpress-wp`
2. Activate the plugin from **Plugins → Installed Plugins**
3. Go to **Settings → ImgPress** and enter your API URL

## Configuration

All settings live under **Settings → ImgPress**.

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

## Usage

### Automatic compression

With auto-compress enabled, every uploaded file is sent to the API before WordPress creates the attachment. No action required.

### Single file compression

In the media library list view, find the **⚡ ImgPress** column. Files that haven't been compressed show a **Compress** button — click it to compress that file in place.

### Bulk compression

Go to **Media → Bulk Compress**. The page shows how many files are pending, then lets you start a batch run that processes each file sequentially and displays a live progress bar and results table.

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
│   └── class-bulk-compress.php  # Bulk compress page & AJAX handlers
├── admin/
│   ├── page-settings.php        # Settings page template
│   └── page-bulk.php            # Bulk compress page template
└── assets/
    ├── admin.js                 # jQuery for AJAX actions and progress UI
    └── admin.css                # Admin styles
```

## License

MIT — see [LICENSE](LICENSE) for details.

## Author

[Hoang Kiem](https://github.com/binjuhor)
