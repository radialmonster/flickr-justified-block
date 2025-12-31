<?php
/**
 * Helper functions for Flickr Justified Block rendering
 *
 * Utility functions for URL parsing, validation, cache access, and data formatting.
 *
 * @package FlickrJustifiedBlock
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// ADMIN SETTINGS
// ============================================================================

/**
 * Safely retrieve a value from the admin settings class.
 *
 * @param string $method  Method name to call on FlickrJustifiedAdminSettings.
 * @param mixed  $default Default value to return when the method is unavailable.
 * @return mixed
 */
function flickr_justified_get_admin_setting($method, $default = null) {
    if (!is_string($method) || empty($method)) {
        return $default;
    }

    if (!class_exists('FlickrJustifiedAdminSettings')) {
        return $default;
    }

    if (!is_callable(['FlickrJustifiedAdminSettings', $method])) {
        return $default;
    }

    $value = call_user_func(['FlickrJustifiedAdminSettings', $method]);

    return null === $value ? $default : $value;
}

// ============================================================================
// SIZE MANAGEMENT
// ============================================================================

/**
 * Retrieve the ordered list of Flickr size identifiers used throughout the plugin.
 *
 * @param bool $include_thumbnails When true, include square thumbnail sizes.
 * @return array<int, string>
 */
function flickr_justified_get_available_flickr_sizes($include_thumbnails = false) {
    static $standard_sizes = null;
    static $sizes_with_thumbnails = null;

    if (null === $standard_sizes) {
        $standard_sizes = [
            'original', 'large6k', 'large5k', 'largef', 'large4k', 'large3k',
            'large2048', 'large1600', 'large1024', 'large',
            'medium800', 'medium640', 'medium500', 'medium',
            'small400', 'small320', 'small240',
        ];
    }

    if (!$include_thumbnails) {
        return $standard_sizes;
    }

    if (null === $sizes_with_thumbnails) {
        $sizes_with_thumbnails = array_merge(
            $standard_sizes,
            ['thumbnail100', 'thumbnail150s', 'thumbnail75s']
        );
    }

    return $sizes_with_thumbnails;
}

/**
 * Select the best image size based on maximum width/height constraints
 *
 * @param array $image_sizes_data Array of size data with URLs, widths, and heights
 * @param int $max_width Maximum desired width
 * @param int $max_height Maximum desired height
 * @return string|null Best matching size key or null if none found
 */
function flickr_justified_select_best_size($image_sizes_data, $max_width = 2048, $max_height = 2048) {
    if (empty($image_sizes_data)) {
        return null;
    }

    // Size preference order (largest to smallest)
    $size_preference = [
        'original', 'large6k', 'large5k', 'large4k', 'large3k',
        'large2048', 'large1600', 'large1024', 'large',
        'medium800', 'medium640', 'medium500', 'medium'
    ];

    $best_size = null;
    $best_area = 0;

    // Find the largest size that fits within our constraints
    foreach ($size_preference as $size_key) {
        if (!isset($image_sizes_data[$size_key])) {
            continue;
        }

        $size_data = $image_sizes_data[$size_key];
        if (!isset($size_data['width'], $size_data['height'])) {
            continue;
        }

        $width = $size_data['width'];
        $height = $size_data['height'];

        // Check if this size fits within our constraints
        if ($width <= $max_width && $height <= $max_height) {
            $area = $width * $height;

            // This size fits - use it if it's the largest we've found so far
            if ($area > $best_area) {
                $best_size = $size_key;
                $best_area = $area;
            }
        }
    }

    // If no size fits within constraints, use the smallest available size
    if (!$best_size) {
        $smallest_sizes = ['medium', 'medium500', 'medium640', 'medium800'];
        foreach ($smallest_sizes as $size_key) {
            if (isset($image_sizes_data[$size_key])) {
                $best_size = $size_key;
                break;
            }
        }
    }

    return $best_size;
}

// ============================================================================
// DATA FORMATTING
// ============================================================================

/**
 * Encode data for safe output within HTML attributes.
 *
 * @param mixed $data Data to encode.
 * @return string
 */
function flickr_justified_encode_json_attr($data) {
    $encoded = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);

    return is_string($encoded) ? $encoded : '';
}

/**
 * Provide a consistent empty response for paginated photoset requests.
 *
 * @param int $page Requested page number.
 * @return array
 */
function flickr_justified_empty_photoset_result($page = 1) {
    $page = max(1, (int) $page);

    return [
        'photos'      => [],
        'has_more'    => false,
        'total'       => 0,
        'page'        => $page,
        'pages'       => 1,
        'album_title' => '',
    ];
}

// ============================================================================
// URL PARSING AND VALIDATION
// ============================================================================

/**
 * Determine whether a URL points to a Flickr photo page.
 *
 * @param string $url Potential Flickr photo URL.
 * @return bool
 */
function flickr_justified_is_flickr_photo_url($url) {
    if (!is_string($url) || '' === $url) {
        return false;
    }

    return (bool) preg_match('#(?:www\.)?flickr\.com/photos/[^/]+/\d+#i', $url);
}

/**
 * Extract the Flickr photo ID from a photo URL.
 *
 * @param string $url Flickr photo URL.
 * @return string Empty string when no ID can be located.
 */
function flickr_justified_extract_photo_id($url) {
    if (!is_string($url) || '' === $url) {
        return '';
    }

    if (preg_match('#flickr\.com/photos/[^/]+/(\d+)#i', $url, $matches) && isset($matches[1])) {
        return $matches[1];
    }

    return '';
}

/**
 * Build the Flickr attribution URL for a photo that appears within a specific album.
 *
 * @param string $photo_url   Base Flickr photo URL.
 * @param string $photoset_id Flickr album/photoset identifier.
 * @param string $user_id     Flickr user or NSID. Optional when derivable from URL.
 *
 * @return string Attribution URL or empty string when unavailable.
 */
function flickr_justified_build_album_photo_attribution_url($photo_url, $photoset_id, $user_id = '') {
    if (!is_string($photo_url) || '' === $photo_url) {
        return '';
    }

    if (!is_string($photoset_id) || '' === $photoset_id) {
        return '';
    }

    $photo_id = flickr_justified_extract_photo_id($photo_url);
    if ('' === $photo_id) {
        return '';
    }

    if (!is_string($user_id) || '' === $user_id) {
        if (preg_match('#flickr\.com/photos/([^/]+)/#i', $photo_url, $matches) && !empty($matches[1])) {
            $user_id = $matches[1];
        }
    }

    if (!is_string($user_id) || '' === $user_id) {
        return '';
    }

    return sprintf(
        'https://flickr.com/photos/%s/%s/in/album-%s/',
        rawurlencode($user_id),
        rawurlencode($photo_id),
        rawurlencode($photoset_id)
    );
}

// ============================================================================
// CACHE WRAPPER FUNCTIONS
// Delegate to FlickrJustifiedCache class for backward compatibility
// ============================================================================

/**
 * Get photo info with dimensions (wrapper for cache class)
 */
function flickr_justified_get_flickr_image_sizes_with_dimensions($page_url, $requested_sizes = ['large', 'original'], $needs_metadata = false) {
    if (!preg_match('#flickr\.com/photos/[^/]+/(\d+)#', $page_url, $matches)) {
        return [];
    }

    $photo_id = $matches[1];
    return FlickrJustifiedCache::get_photo_sizes($photo_id, $page_url, $requested_sizes, $needs_metadata);
}

/**
 * Get photo stats (wrapper for cache class)
 */
function flickr_justified_get_photo_stats($photo_id) {
    return FlickrJustifiedCache::get_photo_stats($photo_id);
}

/**
 * Get photo info (wrapper for cache class)
 */
function flickr_justified_get_photo_info($photo_id, $force_refresh = false) {
    return FlickrJustifiedCache::get_photo_info($photo_id, $force_refresh);
}

/**
 * Resolve user ID (wrapper for cache class)
 */
function flickr_justified_resolve_user_id($username) {
    return FlickrJustifiedCache::resolve_user_id($username);
}

/**
 * Get photoset info (wrapper for cache class)
 */
function flickr_justified_get_photoset_info($user_id, $photoset_id) {
    return FlickrJustifiedCache::get_photoset_info($user_id, $photoset_id);
}

/**
 * Get photoset photos paginated (wrapper for cache class)
 */
function flickr_justified_get_photoset_photos_paginated($user_id, $photoset_id, $page = 1, $per_page = 50) {
    return FlickrJustifiedCache::get_photoset_photos($user_id, $photoset_id, $page, $per_page);
}

// ============================================================================
// EXTERNAL IMAGE DIMENSIONS
// ============================================================================

/**
 * Get image dimensions for non-Flickr images with caching
 *
 * @param string $url Image URL
 * @return array|null ['width' => int, 'height' => int] or null on failure
 */
function flickr_justified_get_external_image_dimensions($url) {
    if (empty($url) || !is_string($url)) {
        return null;
    }

    $cache_key = ['external_dims', md5($url)];

    // Check cache first (consistent with Flickr cache pattern)
    $cached = FlickrJustifiedCache::get($cache_key);
    if ($cached !== false) {
        // Return null if previously failed, dimensions if successful
        return isset($cached['failed']) ? null : $cached;
    }

    // Fetch with timeout protection
    $context = stream_context_create([
        'http' => [
            'timeout' => 3,
            'user_agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo('version') . ')',
            'follow_location' => 1,
            'max_redirects' => 3
        ]
    ]);

    $image_info = @getimagesize($url, $context);

    if ($image_info && isset($image_info[0], $image_info[1])) {
        $dims = [
            'width' => (int) $image_info[0],
            'height' => (int) $image_info[1]
        ];

        // Cache for 1 week (like Flickr data)
        FlickrJustifiedCache::set($cache_key, $dims, WEEK_IN_SECONDS);
        return $dims;
    }

    // Cache failure for 1 hour (avoid repeated failed fetches)
    FlickrJustifiedCache::set($cache_key, ['failed' => true], HOUR_IN_SECONDS);
    return null;
}
