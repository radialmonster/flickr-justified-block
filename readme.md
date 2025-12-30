=== Flickr Justified Block ===
Contributors: RadialMonster
Tags: flickr, justified, gallery, images, block, gutenberg
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Gutenberg block for creating justified Flickr galleries with built-in lightbox, intelligent caching, and automatic album pagination.

== Description ==

Flickr Justified Block creates edge-to-edge justified photo galleries from Flickr albums, individual photos, or direct image URLs. Drop in the block, paste your URLs, and get a responsive gallery with PhotoSwipe lightbox—no configuration required.

### Key Features

**Justified Layout Engine**
True justified galleries that calculate optimal row heights from image aspect ratios. Responsive breakpoints let you control columns per screen size.

**Smart Caching System**
Multi-level caching (request-level + transients) with configurable TTL. Includes cache versioning, negative caching for private photos, and rate-limit detection with automatic backoff.

**Background Cache Warming**
WP-Cron task pre-fetches Flickr data before visitors arrive. Configurable batch sizes and fast/slow modes prevent API rate limits.

**Automatic Album Pagination**
Large Flickr albums load page-by-page as visitors scroll. IntersectionObserver triggers seamless loading with scroll position preservation.

**Image Fallback Recovery**
Detects expired Flickr URLs (404s) and automatically fetches fresh ones via AJAX—no broken images.

**Sort by Views**
Display most popular photos first using cached Flickr view counts. Cache-aware sorting prevents API timeouts on large galleries.

**EXIF Rotation Support**
Handles rotated photos correctly throughout the entire pipeline—layout calculations, CSS transforms, and lightbox display.

**Built-in PhotoSwipe Lightbox**
Full-featured lightbox with Flickr attribution links. No extra plugins needed.

**Mixed Content Support**
Combine Flickr photos, albums, and direct image URLs (JPG, PNG, WebP, AVIF, GIF, SVG) in the same gallery.

== Installation ==

1. Install via **Plugins → Add New** or upload the ZIP manually
2. Activate the plugin
3. (Optional) Add your free Flickr API key at **Settings → Flickr Justified**

== Usage ==

1. Add the **Flickr Justified** block to any post or page
2. Paste URLs in the sidebar (one per line):
   - Flickr photo URLs
   - Flickr album/set URLs
   - Direct image URLs
3. Adjust settings: row height, gap, responsive columns, sort order
4. Publish

== Settings ==

**Settings → Flickr Justified** provides:

- API key configuration with encryption
- Cache duration (default: 7 days)
- Background cache warmer toggle and batch size
- Responsive breakpoints and default columns
- Error handling preferences

== CLI Commands ==

```
wp flickr-justified warm-cache          # Run cache warmer
wp flickr-justified warm-cache --rebuild # Force rebuild all caches
wp flickr-justified clear-cache         # Clear all plugin caches
```

== FAQ ==

= Do I need a Flickr API key? =
Only for Flickr URLs. Direct image URLs work without one.

= How does caching work? =
API responses are cached in WordPress transients. The background warmer pre-fetches data so visitors never wait for API calls.

= What if images fail to load? =
The plugin automatically detects 404s and fetches fresh URLs from Flickr.

== Support ==

Issues and feature requests: [GitHub](https://github.com/radialmonster/flickr-justified-block)

== Privacy ==

The plugin contacts Flickr's API only when processing Flickr URLs. Only photo/album IDs and your API key are sent. Responses are cached locally.
