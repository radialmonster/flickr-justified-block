=== Flickr Justified Block ===
Contributors: RadialMonster
Tags: flickr, justified, gallery, images, block, gutenberg
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create immersive justified photo galleries from Flickr albums or direct image links with a Gutenberg block that includes built-in PhotoSwipe lightbox, album lazy-loading, and powerful responsive controls.

== Description ==

Flickr Justified Block gives you an easy way to build beautiful, edge-to-edge galleries inside the WordPress block editor. Paste Flickr photo page URLs, entire album links, or any direct image URL—the block takes care of the layout, high-resolution fetching, and responsive behaviour for you.

Whether you're showcasing a single album or curating images from multiple sources, the block keeps everything fast, accessible, and mobile-friendly. It ships with a tuned PhotoSwipe lightbox (no extra plugins needed), smart row sizing, and optional fixed-height rows for more controlled designs.

### Highlights

* **Drop-in gallery block** – Add the "Flickr Justified" block, paste URLs (one per line), and you're done.
* **Flickr photo & album support** – Works with individual photo URLs _and_ full albums/sets. Albums expand automatically and continue loading as visitors scroll.
* **Direct image compatibility** – Mix Flickr content with JPG, PNG, WebP, AVIF, GIF, or SVG links hosted anywhere.
* **Built-in PhotoSwipe lightbox** – Optimized for Retina/4K displays with automatic "View on Flickr" attribution buttons.
* **Per-gallery controls** – Limit how many images load for a block and optionally sort Flickr photos by cached daily view counts.
* **Responsive control** – Choose images-per-row per breakpoint in the editor; define default breakpoints and column counts globally in Settings → Flickr Justified.
* **Row height options** – Use automatic height for perfectly justified rows or switch to a fixed pixel height for uniform stripes.
* **Viewport guard** – Limit image height relative to the visitor’s screen so tall images never overflow.
* **Album lazy loading** – Large Flickr sets load page-by-page via the REST API, reducing initial page weight and API calls.
* **Editor previews & validation** – Each URL is checked in the editor sidebar so you can confirm content before publishing.
* **Performance-minded** – API responses are cached (duration configurable), images load lazily, and layout adjusts without blocking paints.
* **Error handling** – Choose to show a friendly message or hide the block entirely when Flickr photos are private or unavailable.

### Requirements & Recommendations

* WordPress 5.0 or newer (block editor required)
* PHP 7.4 or newer
* A free Flickr API key (only required if you want to use Flickr photo/albums URLs; direct image URLs work without it)
* HTTPS recommended when loading external images

== Installation ==

=== Automatic Installation ===

1. In your WordPress dashboard go to **Plugins → Add New**.
2. Search for "Flickr Justified Block".
3. Click **Install Now** and then **Activate**.

=== Manual Installation ===

1. Download the plugin ZIP file.
2. Go to **Plugins → Add New → Upload Plugin** and select the ZIP file.
3. Click **Install Now**, then **Activate** the plugin.

== Setup ==

1. (Optional, but recommended) Get a free Flickr API key:
   * Visit the [Flickr App Garden](https://www.flickr.com/services/apps/create/).
   * Create an app, copy the API key.
   * In WordPress, go to **Settings → Flickr Justified**, paste the key, and press **Test API Key** to confirm. The key is encrypted before it’s stored.
2. Adjust plugin defaults if needed:
   * **Cache Duration** – Controls how long Flickr responses stay cached.
   * **Preload Flickr Data** – Enable the background cache warmer, choose a slow-and-steady mode, and set how many URLs to warm per batch.
   * **Responsive Breakpoints** – Define screen widths and default images per row for each device size.
   * **Error Handling & Messages** – Decide whether to show a notice when photos are private, and customise the text.
   * **Attribution Text** – Set the label used for lightbox attribution buttons.
3. Save changes.

== Creating a Gallery ==

1. Edit any post or page and add the **Flickr Justified** block (Media category).
2. Paste your image sources into the sidebar field—one URL per line. You can mix:
   * Flickr photo page URLs
   * Flickr album/set URLs
   * Direct links to JPG, PNG, WebP, AVIF, GIF, or SVG files
3. Adjust the block options as needed:
   * **Gallery Image Size** – Select the target size for grid thumbnails. The lightbox automatically upgrades to larger sizes when available.
   * **Grid Gap** – Control spacing between items.
   * **Row Height Mode** – Switch between auto (perfectly justified rows) or fixed row height.
   * **Row Height** – Pick a fixed pixel height when using the fixed mode.
   * **Max Viewport Height** – Keep large images within a percentage of the browser height.
   * **Single Image Alignment** – Choose how a lone image should align within the block.
   * **Show how many images** – Leave at 0 for unlimited or enter a maximum number of photos to render and lazy-load for this block.
   * **Sort images** – Keep the original entry order or use cached Flickr view counts to show the most popular photos first.
   * **Responsive Settings** – Override images-per-row per breakpoint for this gallery.
4. Preview the block. Each URL will display a thumbnail or album card inside the editor so you can verify the feed.
5. Publish. On the front end visitors get a responsive justified layout, PhotoSwipe lightbox, and auto-loading albums.

== Working with Flickr Albums ==

* Paste a Flickr album/set URL just like a photo URL. The plugin fetches the first page of photos automatically.
* Album galleries load additional pages as the visitor approaches the end of the grid. A loading indicator appears while new rows are fetched.
* Each image links back to Flickr and opens in the built-in lightbox with attribution.

== Frequently Asked Questions ==

= Do I need a Flickr API key? =

Only if you want to use Flickr photo or album URLs. The block still works with direct image URLs without a key. If you add Flickr content, grab a free key from the Flickr App Garden and add it under **Settings → Flickr Justified**.

= How do I change the number of columns? =

Use the **Responsive Settings** panel in the block sidebar to set images-per-row for different screen sizes. You can also set site-wide defaults and breakpoints under **Settings → Flickr Justified**.

= Can I combine Flickr photos and direct image links? =

Yes! Mix and match any supported URLs in the same gallery. Albums can live alongside individual images.

= What happens when a photo is private or missing? =

You control the behaviour in **Settings → Flickr Justified**. Choose to display a custom error message or hide the gallery entirely if Flickr returns a privacy or availability error.

= Does the plugin support lightbox galleries out of the box? =

Absolutely. Every gallery automatically uses the bundled PhotoSwipe lightbox. No additional plugins or scripts are required.

= How does caching work? =

Flickr API responses (photo data, detailed per-photo info such as view/comment/favorite counts, album pages, user lookups) are cached in WordPress to reduce API usage and speed up pages. You can change the cache duration in the plugin settings, and clear the cache manually if you need to force a refresh.

When **Preload Flickr Data** is enabled the plugin registers a WP-Cron task (`flickr_justified_run_cache_warmer`) that periodically walks every saved Flickr Justified block, prefetching the same API responses visitors would otherwise trigger on first load. Each warm respects the Cache Duration you configure, so primed responses stick around for that length of time. Need it sooner? Run `wp flickr-justified warm-cache --rebuild` from WP-CLI or trigger a single cron run with `wp cron event run flickr_justified_run_cache_warmer`.

== Support ==

For help, feature requests, or bug reports please open an issue on the [GitHub repository](https://github.com/radialmonster/flickr-justified-block) or use the WordPress.org support forums.

== Privacy ==

When you paste Flickr URLs the plugin contacts the Flickr API to retrieve image information. Only photo IDs and your API key (if configured) are sent. Responses are cached locally; no other personal data is transmitted. Direct image URLs are loaded directly from their host without contacting Flickr.
