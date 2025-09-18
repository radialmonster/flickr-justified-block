=== Flickr Justified Block ===
Contributors: RadialMonster
Tags: flickr, justified, gallery, images, block, gutenberg
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Display Flickr photos and other images in a beautiful responsive justified gallery layout with this custom Gutenberg block.

== Description ==

**Flickr Justified Block** is a powerful WordPress block that lets you create stunning justified galleries from Flickr photos and other images. Simply paste image URLs (one per line) and the block automatically arranges them in a responsive justified gallery layout.

### Key Features

* **🎨 Beautiful Justified Layout**: CSS-powered responsive justified gallery design that works on all devices
* **📸 Flickr Integration**: Paste Flickr photo page URLs and automatically fetch high-resolution versions using the Flickr API
* **⚙️ Customizable Settings**: Configure columns (1-6), gap spacing (0-48px), and image quality for Flickr photos
* **🎲 Random Gallery Breakouts**: Optionally split galleries into multiple sections with configurable probability
* **🖼️ Lightbox Controls**: Set maximum dimensions for lightbox display of images
* **📱 Fully Responsive**: Automatically adjusts to 2 columns on tablets and 1 column on mobile
* **♿ Accessibility Ready**: Includes proper focus states and supports high contrast and reduced motion preferences
* **🚀 Performance Optimized**: Image caching, lazy loading, and efficient API usage
* **🎯 Block Editor Integration**: Clean, intuitive interface that fits seamlessly into the WordPress block editor
* **⚙️ Admin Settings Page**: Easy-to-use admin interface for managing Flickr API keys

### How to Use

1. Add the "Flickr Justified" block to any post or page
2. In the block sidebar, paste your image URLs (one per line)
3. Customize columns, spacing, and image size preferences
4. Publish and enjoy your beautiful masonry gallery!

### Supported URL Types

* **Flickr Photo Pages**: `https://www.flickr.com/photos/username/1234567890/` (automatically fetches high-res versions)
* **Direct Image URLs**: Any direct link to JPG, PNG, WebP, AVIF, GIF, or SVG images
* **Mixed Content**: Combine both Flickr and direct URLs in the same gallery

### Flickr API Setup

To use Flickr photo page URLs, you'll need a free Flickr API key:

1. Visit [Flickr App Garden](https://www.flickr.com/services/apps/create/)
2. Create a new app and get your API key
3. Go to Settings → Flickr Justified in your WordPress admin and enter your API key

Without an API key, the block will still work with direct image URLs.

== Installation ==

### Automatic Installation

1. Go to your WordPress admin dashboard
2. Navigate to Plugins → Add New
3. Search for "Flickr Masonry Block"
4. Click "Install Now" and then "Activate"

### Manual Installation

1. Download the plugin zip file
2. Go to Plugins → Add New → Upload Plugin
3. Choose the zip file and click "Install Now"
4. Activate the plugin

### Setup

1. (Optional) Set up your Flickr API key via Settings → Flickr Justified for Flickr integration
2. Create or edit a post/page
3. Add the "Flickr Justified" block from the Media category
4. Start adding your image URLs!

== Frequently Asked Questions ==

= Do I need a Flickr API key? =

A Flickr API key is only required if you want to use Flickr photo page URLs. The block works perfectly with direct image URLs without any API key. Get a free API key at [Flickr App Garden](https://www.flickr.com/services/apps/create/) and add it via Settings → Flickr Justified.

= What image formats are supported? =

The block supports JPG, PNG, WebP, AVIF, GIF, and SVG formats. For Flickr photos, it automatically fetches the best available quality.

= How do I change the number of columns? =

Use the "Columns" setting in the block sidebar. You can choose from 1-6 columns. The layout automatically becomes responsive on smaller screens.

= Can I mix Flickr URLs with direct image URLs? =

Yes! You can combine both Flickr photo page URLs and direct image URLs in the same gallery.

= How does caching work? =

Flickr API responses are cached for 1 week to improve performance and reduce API usage. The cache automatically refreshes when needed.

= What are random breakouts? =

Random breakouts allow galleries to be split into multiple sections with configurable probability. Enable this feature in the block settings to create more dynamic, varied gallery layouts.

= Is it mobile-friendly? =

Absolutely! The justified gallery layout automatically adjusts to 2 columns on tablets and 1 column on mobile devices for optimal viewing.



== Support ==

For support, feature requests, or bug reports, please visit our [GitHub repository](https://github.com/RadialMonster/flickr-justified-block) or contact us through the WordPress.org support forums.

== Privacy Policy ==

This plugin may connect to the Flickr API when processing Flickr photo URLs. No personal data is sent to external services except for the photo IDs needed to fetch image information. All API responses are cached locally to minimize external requests.