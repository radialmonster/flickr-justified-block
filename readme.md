# Flickr Justified Block

A powerful WordPress Gutenberg block that creates beautiful justified photo galleries from Flickr albums, individual photos, or direct image URLs. Features intelligent caching, automatic pagination, and built-in lightbox.

![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/License-GPLv2-green.svg)

---

## ✨ Features

### 🎨 Justified Layout Engine
- **True justified galleries** with optimal row heights calculated from image aspect ratios
- **Responsive breakpoints** - control columns per screen size (mobile, tablet, desktop)
- **Auto or fixed row heights** with viewport-aware sizing

### 🚀 Smart Caching System
- **Multi-level caching** - request-level + WordPress transients
- **Configurable TTL** with cache versioning
- **Negative caching** for private photos
- **Rate-limit detection** with automatic backoff

### ⚡ Background Cache Warming
- **WP-Cron integration** pre-fetches Flickr data before visitors arrive
- **Configurable batch sizes** and fast/slow modes
- **Prevents API rate limits** during high traffic

### 📄 Automatic Album Pagination
- **Large albums load page-by-page** as visitors scroll
- **IntersectionObserver** triggers seamless loading
- **Scroll position preservation** for smooth UX

### 🔄 Image Fallback Recovery
- **Detects expired Flickr URLs** (404s)
- **Automatically fetches fresh URLs** via AJAX
- **No broken images** - ever

### 📊 Sort by Views
- Display **most popular photos first** using cached Flickr view counts
- **Cache-aware sorting** prevents API timeouts on large galleries

### 📐 EXIF Rotation Support
- **Handles rotated photos correctly** throughout entire pipeline
- Layout calculations, CSS transforms, and lightbox display

### 🖼️ Built-in PhotoSwipe Lightbox
- **Full-featured lightbox** with Flickr attribution links
- **No extra plugins needed**
- **Responsive and touch-friendly**

### 🌐 Mixed Content Support
- Combine Flickr photos, albums, and direct image URLs in the same gallery
- **Supports:** JPG, PNG, WebP, AVIF, GIF, SVG

---

## 📋 Requirements

- **WordPress:** 5.0 or higher
- **PHP:** 7.4 or higher
- **Flickr API Key:** Required for Flickr URLs (free from [Flickr App Garden](https://www.flickr.com/services/apps/create/))

---

## 🔧 Installation

### From WordPress Admin

1. Go to **Plugins → Add New**
2. Search for "Flickr Justified Block"
3. Click **Install Now**
4. Click **Activate**
5. (Optional) Go to **Settings → Flickr Justified** and add your API key

### Manual Installation

1. Download the latest release from [GitHub Releases](https://github.com/radialmonster/flickr-justified-block/releases)
2. Upload the `flickr-justified-block` folder to `/wp-content/plugins/`
3. Activate the plugin through the **Plugins** menu
4. (Optional) Configure your API key at **Settings → Flickr Justified**

### From Source

```bash
git clone https://github.com/radialmonster/flickr-justified-block.git
cd flickr-justified-block
# Copy to your WordPress plugins directory
cp -r . /path/to/wordpress/wp-content/plugins/flickr-justified-block/
```

---

## 🚀 Quick Start

### 1. Add the Block

In the WordPress block editor:
1. Click the **+** button to add a new block
2. Search for "Flickr Justified"
3. Add the block to your post or page

### 2. Add URLs

In the block sidebar, paste URLs (one per line):

```
https://www.flickr.com/photos/username/albums/72157605291217770
https://www.flickr.com/photos/username/123456789
https://example.com/direct-image.jpg
```

**Supported URL formats:**
- Flickr photo: `https://flickr.com/photos/username/123456789`
- Flickr album: `https://flickr.com/photos/username/albums/72157605291217770`
- Flickr set: `https://flickr.com/photos/username/sets/72157605291217770`
- Direct image: `https://example.com/image.jpg`

### 3. Customize Settings

Adjust settings in the block sidebar:
- **Row Height:** Auto or fixed pixel height
- **Gap:** Space between images (px)
- **Image Size:** Quality/size from Flickr
- **Responsive Columns:** Columns per breakpoint
- **Sort Order:** Input order or by views
- **Max Photos:** Limit number of photos (0 = unlimited)

### 4. Publish

Click **Publish** or **Update** - your gallery is live!

---

## ⚙️ Configuration

### Global Settings

Go to **Settings → Flickr Justified** to configure:

#### API Key
- **Flickr API Key:** Get one from [Flickr App Garden](https://www.flickr.com/services/apps/create/)
- Keys are encrypted when stored in the database

#### Cache Settings
- **Cache Duration:** How long to cache Flickr data (default: 7 days)
- **Background Cache Warmer:** Enable/disable automatic cache warming
- **Batch Size:** Number of URLs to warm per WP-Cron run

#### Responsive Breakpoints
- Customize screen width breakpoints for responsive behavior
- Default columns per breakpoint

#### Error Handling
- **Show Placeholder:** Display placeholder for unavailable photos
- **Show Error:** Display error message
- **Show Nothing:** Skip unavailable photos silently

---

## 🖥️ WP-CLI Commands

Manage caches via WP-CLI:

### Warm Cache
```bash
# Warm cache for all galleries
wp flickr-justified warm-cache

# Force rebuild all caches
wp flickr-justified warm-cache --rebuild
```

### Clear Cache
```bash
# Clear all plugin caches
wp flickr-justified clear-cache
```

---

## 🎨 Customization

### CSS Custom Properties

The plugin uses CSS custom properties for easy styling:

```css
.flickr-justified-grid {
    --gap: 12px; /* Gap between images */
}
```

### Custom Styling

Target the plugin classes:

```css
/* Gallery container */
.flickr-justified-grid {
    margin: 2rem 0;
}

/* Individual photo cards */
.flickr-justified-card {
    border-radius: 8px;
    overflow: hidden;
}

/* Images */
.flickr-justified-card img {
    transition: transform 0.3s ease;
}

.flickr-justified-card img:hover {
    transform: scale(1.05);
}
```

---

## 🏗️ Architecture

### File Structure

```
flickr-justified-block/
├── flickr-justified-block.php    # Main plugin file
├── block.json                     # Block configuration
├── includes/
│   ├── admin-settings.php         # Admin settings page
│   ├── cache.php                  # Cache management
│   ├── cache-warmers.php          # Background cache warming
│   ├── cli-warmers.php            # WP-CLI commands
│   ├── render.php                 # Main render orchestration
│   ├── render-ajax.php            # AJAX handlers
│   ├── render-helpers.php         # Helper functions
│   ├── render-html.php            # HTML generation
│   └── render-photo-fetcher.php   # Flickr API integration
└── assets/
    ├── css/
    │   └── style.css              # Frontend styles
    └── js/
        └── editor.js              # Block editor script
```

### Caching Strategy

1. **Request-level cache:** In-memory cache for single request
2. **WordPress transients:** Persistent cache across requests
3. **Local database:** `wp_fjb_photo_meta` table for photo metadata
4. **Background warmer:** Pre-fetches data via WP-Cron

### Performance Optimizations

- **Lazy loading:** Images load only when visible
- **Progressive enhancement:** Core functionality works without JavaScript
- **Srcset/Sizes:** Responsive image loading
- **Rate limiting:** Protects against Flickr API limits
- **Pagination:** Large albums load incrementally

---

## 🐛 Troubleshooting

### Gallery Not Loading

**Check API Key:**
```bash
wp option get flickr_justified_api_key
```

**Check cache:**
```bash
wp flickr-justified clear-cache
wp flickr-justified warm-cache
```

### Rate Limited by Flickr

The plugin automatically handles rate limits, but if you're consistently rate limited:

1. Reduce **Batch Size** in settings
2. Enable **Slow Mode** for cache warmer
3. Increase **Cache Duration** to reduce API calls

### Images Not Displaying

**Clear and rebuild cache:**
```bash
wp flickr-justified warm-cache --rebuild
```

**Check photo permissions:** Ensure photos are public on Flickr

### 504 Gateway Timeout

For very large albums (1000+ photos):
1. Ensure cache is warmed: `wp flickr-justified warm-cache`
2. Increase PHP `max_execution_time`
3. Enable object caching (Redis/Memcached)

---

## 🤝 Contributing

Contributions are welcome! Please follow these guidelines:

1. **Fork** the repository
2. Create a **feature branch** (`git checkout -b feature/amazing-feature`)
3. **Commit** your changes (`git commit -m 'Add amazing feature'`)
4. **Push** to the branch (`git push origin feature/amazing-feature`)
5. Open a **Pull Request**

### Development Setup

```bash
# Clone the repository
git clone https://github.com/radialmonster/flickr-justified-block.git
cd flickr-justified-block

# Set up WordPress development environment
# (Docker, Local, or your preferred method)

# Enable WP_DEBUG in wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

---

## 📄 License

This plugin is licensed under the **GPLv2 or later**.

```
Copyright (C) 2024 RadialMonster

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

---

## 🙏 Credits

- **PhotoSwipe:** [photoswipe.com](https://photoswipe.com/)
- **Flickr API:** [flickr.com/services/api](https://www.flickr.com/services/api/)

---

## 📞 Support

- **Issues:** [GitHub Issues](https://github.com/radialmonster/flickr-justified-block/issues)
- **Documentation:** [Wiki](https://github.com/radialmonster/flickr-justified-block/wiki)
- **WordPress.org:** [Plugin Page](https://wordpress.org/plugins/flickr-justified-block/)

---

## 🌟 Show Your Support

If you find this plugin helpful, please:
- ⭐ **Star** the repository
- 🐛 **Report bugs** or suggest features
- 💬 **Share** with others
- ☕ [**Buy me a coffee**](https://radialmonster.github.io/send-a-virtual-gift/)

---

**Made with ❤️ by [RadialMonster](https://radialmonster.com)**
