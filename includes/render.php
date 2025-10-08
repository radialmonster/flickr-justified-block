<?php
/**
 * Render callback for Flickr Justified Block
 *
 * @package FlickrJustifiedBlock
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Retrieve the name of the option used to store the transient registry.
 *
 * @return string
 */
function flickr_justified_get_transient_registry_option_name() {
    return 'flickr_justified_transient_registry';
}

/**
 * Record a transient key for later cache purging.
 *
 * @param string $transient Transient identifier.
 * @param bool   $is_site   Whether the transient is a site-wide transient.
 */
function flickr_justified_register_transient_key($transient, $is_site = false) {
    if (!is_string($transient)) {
        return;
    }

    $transient = trim($transient);

    if ('' === $transient) {
        return;
    }

    $option_name = flickr_justified_get_transient_registry_option_name();
    $registry    = get_option($option_name, []);

    if (!is_array($registry)) {
        $registry = [];
    }

    $type = $is_site ? 'site' : 'transient';

    if (isset($registry[$transient]) && $registry[$transient] === $type) {
        return;
    }

    $registry[$transient] = $type;

    update_option($option_name, $registry, false);
}

/**
 * Retrieve the cached registry of transient keys.
 *
 * @return array<string, string>
 */
function flickr_justified_get_transient_registry() {
    $option_name = flickr_justified_get_transient_registry_option_name();
    $registry    = get_option($option_name, []);

    if (!is_array($registry)) {
        return [];
    }

    $sanitized = [];

    foreach ($registry as $key => $type) {
        if (!is_string($key) || '' === trim($key)) {
            continue;
        }

        $type = 'site' === $type ? 'site' : 'transient';
        $sanitized[trim($key)] = $type;
    }

    return $sanitized;
}

/**
 * Clear the transient registry option.
 */
function flickr_justified_clear_transient_registry() {
    delete_option(flickr_justified_get_transient_registry_option_name());
}

/**
 * Wrapper for set_transient that records keys for later purging.
 *
 * @param string $transient  Transient name.
 * @param mixed  $value      Value to store.
 * @param int    $expiration Expiration in seconds.
 *
 * @return bool Whether the transient was set.
 */
function flickr_justified_set_transient($transient, $value, $expiration = 0) {
    $result = set_transient($transient, $value, $expiration);

    if ($result) {
        flickr_justified_register_transient_key($transient, false);
    }

    return $result;
}

/**
 * Wrapper for set_site_transient with registry support.
 *
 * @param string $transient  Transient name.
 * @param mixed  $value      Value to store.
 * @param int    $expiration Expiration in seconds.
 *
 * @return bool Whether the transient was set.
 */
function flickr_justified_set_site_transient($transient, $value, $expiration = 0) {
    $result = set_site_transient($transient, $value, $expiration);

    if ($result) {
        flickr_justified_register_transient_key($transient, true);
    }

    return $result;
}

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

/**
 * Retrieve the configured Flickr API key.
 *
 * @return string
 */
function flickr_justified_get_api_key() {
    static $api_key = null;

    if (null !== $api_key) {
        return $api_key;
    }

    $raw_key = flickr_justified_get_admin_setting('get_api_key', '');
    $api_key = is_string($raw_key) ? trim($raw_key) : '';

    return $api_key;
}

/**
 * Get the Flickr size label mapping used throughout the renderer.
 *
 * @return array
 */
function flickr_justified_get_size_label_map() {
    static $size_mapping = null;

    if (null === $size_mapping) {
        $size_mapping = [
            'original'    => ['Original'],
            'large6k'     => ['Large 6144', 'Original'],
            'large5k'     => ['Large 5120', 'Large 6144', 'Original'],
            'largef'      => ['Large 4096', 'Large 5120', 'Original'],
            'large4k'     => ['Large 4096', 'Large 5120', 'Original'],
            'large3k'     => ['Large 3072', 'Large 4096', 'Original'],
            'large2048'   => ['Large 2048', 'Large 3072', 'Original'],
            'large1600'   => ['Large 1600', 'Large 2048', 'Original'],
            'large1024'   => ['Large 1024', 'Large 1600', 'Original'],
            'large'       => ['Large', 'Large 1024', 'Original'],
            'medium800'   => ['Medium 800', 'Large', 'Original'],
            'medium640'   => ['Medium 640', 'Medium 800', 'Large'],
            'medium500'   => ['Medium', 'Medium 640', 'Large'],
            'medium'      => ['Medium', 'Medium 640', 'Large'],
            'small400'    => ['Small 400', 'Medium'],
            'small320'    => ['Small 320', 'Small 400', 'Medium'],
            'small240'    => ['Small', 'Small 320', 'Medium'],
            'thumbnail100' => ['Thumbnail'],
            'thumbnail150s' => ['Large Square 150', 'Large Square', 'Square'],
            'thumbnail75s'  => ['Square 75', 'Square'],
        ];
    }

    return $size_mapping;
}

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

/**
 * Fallback: Map API sizes directly to requested sizes (when direct URL construction fails)
 */
function flickr_justified_map_api_sizes_to_requested($api_sizes, $requested_sizes) {
    $size_mapping = flickr_justified_get_size_label_map();

    $result = [];

    foreach ($requested_sizes as $requested_size) {
        $preferred_labels = $size_mapping[$requested_size] ?? [$requested_size];

        foreach ($preferred_labels as $label) {
            foreach ($api_sizes as $size_info) {
                if (isset($size_info['label'], $size_info['source']) &&
                    $size_info['label'] === $label && !empty($size_info['source'])) {
                    $result[$requested_size] = esc_url_raw($size_info['source']);
                    break 2;
                }
            }
        }
    }

    return $result;
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

/**
 * Enhanced version of flickr_justified_get_flickr_image_sizes that includes dimensions
 *
 * @param string $page_url        Flickr photo page URL.
 * @param array  $requested_sizes Sizes to request from the API.
 * @param bool   $needs_metadata  Whether to fetch supplemental metadata in addition to sizes.
 * @return array
 */
function flickr_justified_get_flickr_image_sizes_with_dimensions($page_url, $requested_sizes = ['large', 'original'], $needs_metadata = false) {
    if (!preg_match('#flickr\.com/photos/[^/]+/(\d+)#', $page_url, $matches)) {
        return [];
    }

    $photo_id = $matches[1];
    $cache_key = 'flickr_justified_dims_' . $photo_id . '_' . md5(implode(',', $requested_sizes)) . '_' . (int) $needs_metadata;

    // Check cache first
    $cached_result = get_transient($cache_key);
    if (!empty($cached_result) && is_array($cached_result)) {
        flickr_justified_register_transient_key($cache_key);
        return $cached_result;
    }

    // Get API key from settings
    $api_key = flickr_justified_get_api_key();

    if (empty($api_key)) {
        return [];
    }


    // Get available sizes from API with dimensions
    $api_url = add_query_arg([
        'method' => 'flickr.photos.getSizes',
        'api_key' => $api_key,
        'photo_id' => $photo_id,
        'format' => 'json',
        'nojsoncallback' => 1,
    ], 'https://api.flickr.com/services/rest/');

    $response = wp_remote_get($api_url, [
        'timeout' => 10,
        'user-agent' => 'WordPress Flickr Justified Block'
    ]);

    if (is_wp_error($response)) {
        return [];
    }

    $response_code = (int) wp_remote_retrieve_response_code($response);
    if ($response_code < 200 || $response_code >= 300) {
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data['sizes']['size'])) {
        return [];
    }


    // Build result with URLs and dimensions
    $result = flickr_justified_map_api_sizes_to_requested_with_dims($data['sizes']['size'], $requested_sizes);

    if (!empty($result)) {
        if ($needs_metadata) {
            $photo_info = flickr_justified_get_photo_info($photo_id);

            if (!empty($photo_info)) {
                $result['_photo_info'] = $photo_info;

                $stats = flickr_justified_extract_photo_stats_from_info($photo_info);
                if (!empty($stats)) {
                    $result['_stats'] = $stats;
                }

                $rotation = flickr_justified_extract_rotation_from_info($photo_info);
                if ($rotation) {
                    $result['_rotation'] = $rotation;
                }
            }
        }

        // Cache the results
        $cache_duration = (int) flickr_justified_get_admin_setting('get_cache_duration', WEEK_IN_SECONDS);
        if ($cache_duration <= 0) {
            $cache_duration = WEEK_IN_SECONDS;
        }
        flickr_justified_set_transient($cache_key, $result, $cache_duration);
    }

    return $result;
}

/**
 * Map API sizes to requested sizes including dimensions
 */
function flickr_justified_map_api_sizes_to_requested_with_dims($api_sizes, $requested_sizes) {
    $size_mapping = flickr_justified_get_size_label_map();

    $result = [];

    foreach ($requested_sizes as $requested_size) {
        $preferred_labels = $size_mapping[$requested_size] ?? [$requested_size];

        foreach ($preferred_labels as $label) {
            foreach ($api_sizes as $size_info) {
                if (isset($size_info['label'], $size_info['source'], $size_info['width'], $size_info['height']) &&
                    $size_info['label'] === $label && !empty($size_info['source'])) {
                    $result[$requested_size] = [
                        'url' => esc_url_raw($size_info['source']),
                        'width' => (int)$size_info['width'],
                        'height' => (int)$size_info['height']
                    ];
                    break 2;
                }
            }
        }
    }

    return $result;
}

/**
 * Normalize a Flickr rotation value to the 0-359 range.
 *
 * @param mixed $rotation Raw rotation value returned by the API.
 * @return int Normalized rotation in degrees.
 */
function flickr_justified_normalize_rotation($rotation) {
    if (!is_numeric($rotation)) {
        return 0;
    }

    $normalized = (int) round((float) $rotation);
    $normalized %= 360;

    if ($normalized < 0) {
        $normalized += 360;
    }

    return $normalized;
}

/**
 * Determine whether width/height should be swapped for a given rotation.
 *
 * @param int $rotation Rotation in degrees.
 * @return bool
 */
function flickr_justified_should_swap_dimensions($rotation) {
    $rotation = flickr_justified_normalize_rotation($rotation);

    return in_array($rotation, [90, 270], true);
}

/**
 * Apply rotation-aware width/height swapping to a dimensions array.
 *
 * @param array $dimensions Array with optional width/height keys.
 * @param int   $rotation   Rotation in degrees.
 * @return array
 */
function flickr_justified_apply_rotation_to_dimensions($dimensions, $rotation) {
    if (!is_array($dimensions)) {
        return $dimensions;
    }

    if (!isset($dimensions['width'], $dimensions['height'])) {
        return $dimensions;
    }

    if (!flickr_justified_should_swap_dimensions($rotation)) {
        return $dimensions;
    }

    $original_width = (int) $dimensions['width'];
    $original_height = (int) $dimensions['height'];

    $dimensions['width'] = $original_height;
    $dimensions['height'] = $original_width;

    return $dimensions;
}

/**
 * Extract rotation information from a Flickr photo info response.
 *
 * @param array $photo_info Photo information array.
 * @return int Rotation in degrees (0 when unavailable).
 */
function flickr_justified_extract_rotation_from_info($photo_info) {
    if (!is_array($photo_info) || empty($photo_info)) {
        return 0;
    }

    if (isset($photo_info['rotation'])) {
        return flickr_justified_normalize_rotation($photo_info['rotation']);
    }

    return 0;
}

/**
 * Retrieve view, comment, and favorite counts for a Flickr photo.
 *
 * @param string $photo_id Flickr photo ID.
 * @param string|null $date Date string (YYYY-MM-DD). Defaults to current day in UTC.
 * @return array Associative array with views, comments, and favorites (empty on failure).
 */
function flickr_justified_get_photo_stats($photo_id) {
    $photo_info = flickr_justified_get_photo_info($photo_id);

    if (empty($photo_info)) {
        return [];
    }

    return flickr_justified_extract_photo_stats_from_info($photo_info);
}

/**
 * Retrieve extended Flickr photo information via the API.
 *
 * @param string $photo_id Flickr photo ID.
 * @return array Associative array of photo data from flickr.photos.getInfo.
 */
function flickr_justified_get_photo_info($photo_id) {
    $photo_id = trim((string) $photo_id);

    if ('' === $photo_id) {
        return [];
    }

    $cache_key = 'flickr_justified_photo_info_' . $photo_id;
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        flickr_justified_register_transient_key($cache_key);
        return $cached;
    }

    $api_key = flickr_justified_get_api_key();
    if (empty($api_key)) {
        return [];
    }

    $query_args = [
        'method' => 'flickr.photos.getInfo',
        'api_key' => $api_key,
        'photo_id' => $photo_id,
        'format' => 'json',
        'nojsoncallback' => 1,
    ];

    /**
     * Filter the Flickr photo info API request query arguments.
     *
     * Allows advanced integrations to supply authentication tokens or request extras.
     *
     * @param array  $query_args Request query arguments.
     * @param string $photo_id   Flickr photo ID.
     */
    $query_args = apply_filters('flickr_justified_photo_info_query_args', $query_args, $photo_id);

    $api_url = add_query_arg($query_args, 'https://api.flickr.com/services/rest/');

    $response = wp_remote_get($api_url, [
        'timeout' => 10,
        'user-agent' => 'WordPress Flickr Justified Block'
    ]);

    if (is_wp_error($response)) {
        return [];
    }

    $response_code = (int) wp_remote_retrieve_response_code($response);
    if ($response_code < 200 || $response_code >= 300) {
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data['photo']) || !is_array($data['photo'])) {
        return [];
    }

    $photo_info = $data['photo'];

    $cache_duration = (int) flickr_justified_get_admin_setting('get_cache_duration', WEEK_IN_SECONDS);
    if ($cache_duration <= 0) {
        $cache_duration = WEEK_IN_SECONDS;
    }

    flickr_justified_set_transient($cache_key, $photo_info, $cache_duration);

    return $photo_info;
}

/**
 * Extract view, comment, and favorite counts from cached photo info.
 *
 * @param array $photo_info Photo information retrieved from flickr.photos.getInfo.
 * @return array
 */
function flickr_justified_extract_photo_stats_from_info($photo_info) {
    if (!is_array($photo_info) || empty($photo_info)) {
        return [];
    }

    $views = isset($photo_info['views']) ? max(0, (int) $photo_info['views']) : 0;

    $comments = 0;
    if (isset($photo_info['comments'])) {
        if (is_array($photo_info['comments']) && isset($photo_info['comments']['_content'])) {
            $comments = (int) $photo_info['comments']['_content'];
        } elseif (isset($photo_info['comments']) && is_scalar($photo_info['comments'])) {
            $comments = (int) $photo_info['comments'];
        }
    }

    $favorites = 0;
    if (isset($photo_info['count_faves'])) {
        $favorites = (int) $photo_info['count_faves'];
    } elseif (isset($photo_info['favorites'])) {
        if (is_array($photo_info['favorites']) && isset($photo_info['favorites']['_content'])) {
            $favorites = (int) $photo_info['favorites']['_content'];
        } elseif (is_scalar($photo_info['favorites'])) {
            $favorites = (int) $photo_info['favorites'];
        }
    }

    $date = '';
    if (isset($photo_info['dates']['lastupdate'])) {
        $timestamp = (int) $photo_info['dates']['lastupdate'];
        if ($timestamp > 0) {
            $date = gmdate('Y-m-d', $timestamp);
        }
    }

    return [
        'views' => $views,
        'comments' => max(0, $comments),
        'favorites' => max(0, $favorites),
        'date' => $date,
    ];
}

/**
 * Parse Flickr set/album URL to extract photoset_id and user_id
 *
 * @param string $url Flickr set URL
 * @return array|false Array with photoset_id and user_id, or false if invalid
 */
function flickr_justified_parse_set_url($url) {
    // Handle different Flickr set URL formats:
    // http://flickr.com/photos/username/sets/72157600268349682/
    // https://www.flickr.com/photos/username/albums/72157600268349682/
    // https://flickr.com/photos/username/sets/72157600268349682

    if (empty($url) || !is_string($url)) {
        return false;
    }

    $patterns = [
        // Standard sets format: /photos/username/sets/photoset_id
        '#(?:www\.)?flickr\.com/photos/([^/]+)/sets/(\d+)#i',
        // Albums format: /photos/username/albums/photoset_id
        '#(?:www\.)?flickr\.com/photos/([^/]+)/albums/(\d+)#i',
        // Albums with specific photo: /photos/username/albums/photoset_id/with/photo_id
        '#(?:www\.)?flickr\.com/photos/([^/]+)/albums/(\d+)/with/(\d+)#i',
        // Sets with specific photo: /photos/username/sets/photoset_id/with/photo_id
        '#(?:www\.)?flickr\.com/photos/([^/]+)/sets/(\d+)/with/(\d+)#i'
    ];

    foreach ($patterns as $index => $pattern) {
        if (preg_match($pattern, $url, $matches)) {

            // Validate that we got the expected matches
            if (isset($matches[1], $matches[2]) && !empty($matches[1]) && !empty($matches[2])) {
                $result = [
                    'user_id' => trim($matches[1]),
                    'photoset_id' => $matches[2],
                    'url' => $url
                ];

                // If there's a /with/photo_id parameter, include it
                if (isset($matches[3]) && !empty($matches[3])) {
                    $result['with_photo_id'] = $matches[3];
                }

                return $result;
            }
        }
    }

    return false;
}

/**
 * Get all photos from a Flickr set/album using the API
 *
 * @param string $user_id Flickr user ID or username
 * @param string $photoset_id Flickr photoset ID
 * @param string $set_url Original set URL for caching
 * @return array Array of photo URLs or empty array on failure
 */
function flickr_justified_get_photoset_photos($user_id, $photoset_id, $set_url = '') {
    // Use the paginated function to get first page
    $result = flickr_justified_get_photoset_photos_paginated($user_id, $photoset_id, 1, 50);
    return $result['photos'];
}

/**
 * Resolve Flickr username to numeric user ID
 *
 * @param string $username Flickr username
 * @return string|false Numeric user ID or false on failure
 */
function flickr_justified_resolve_user_id($username) {
    if (empty($username) || !is_string($username)) {
        return false;
    }

    // If it's already numeric, return as-is
    if (is_numeric($username)) {
        return $username;
    }

    // Check cache first
    $cache_key = 'flickr_justified_user_id_' . md5($username);
    $cached_user_id = get_transient($cache_key);
    if (!empty($cached_user_id)) {
        flickr_justified_register_transient_key($cache_key);
        return $cached_user_id;
    }

    // Get API key
    $api_key = flickr_justified_get_api_key();

    if (empty($api_key)) {
        return false;
    }

    // Make API call to resolve username
    $api_url = add_query_arg([
        'method' => 'flickr.people.findByUsername',
        'api_key' => $api_key,
        'username' => $username,
        'format' => 'json',
        'nojsoncallback' => 1,
    ], 'https://api.flickr.com/services/rest/');

    $response = wp_remote_get($api_url, [
        'timeout' => 10,
        'user-agent' => 'WordPress Flickr Justified Block'
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $response_code = (int) wp_remote_retrieve_response_code($response);
    if ($response_code < 200 || $response_code >= 300) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['user']['id'])) {
        return false;
    }

    $user_id = $data['user']['id'];

    // Cache the result for 24 hours
    flickr_justified_set_transient($cache_key, $user_id, DAY_IN_SECONDS);

    return $user_id;
}

/**
 * Get Flickr photoset information (title, description, etc.)
 *
 * @param string $user_id Flickr user ID or username
 * @param string $photoset_id Flickr photoset ID
 * @return array|false Array with photoset info or false on failure
 */
function flickr_justified_get_photoset_info($user_id, $photoset_id) {

    if (empty($user_id) || empty($photoset_id)) {
        return false;
    }

    // Resolve username to numeric user ID if needed
    $resolved_user_id = flickr_justified_resolve_user_id($user_id);
    if (!$resolved_user_id) {
        return false;
    }

    // Check cache first
    $cache_key = 'flickr_justified_set_info_' . md5($resolved_user_id . '_' . $photoset_id);
    $cached_info = get_transient($cache_key);
    if (!empty($cached_info) && is_array($cached_info)) {
        flickr_justified_register_transient_key($cache_key);
        return $cached_info;
    }

    // Get API key
    $api_key = flickr_justified_get_api_key();

    if (empty($api_key)) {
        return false;
    }

    // Make API call to get photoset info
    $api_url = add_query_arg([
        'method' => 'flickr.photosets.getInfo',
        'api_key' => $api_key,
        'photoset_id' => $photoset_id,
        'user_id' => $resolved_user_id,
        'format' => 'json',
        'nojsoncallback' => 1,
    ], 'https://api.flickr.com/services/rest/');

    $response = wp_remote_get($api_url, [
        'timeout' => 10,
        'user-agent' => 'WordPress Flickr Justified Block'
    ]);

    if (is_wp_error($response)) {
        return false;
    }

    $response_code = (int) wp_remote_retrieve_response_code($response);
    if ($response_code < 200 || $response_code >= 300) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['photoset'])) {
        return false;
    }

    $photoset_info = [
        'title' => isset($data['photoset']['title']['_content']) ? sanitize_text_field($data['photoset']['title']['_content']) : '',
        'description' => isset($data['photoset']['description']['_content']) ? sanitize_textarea_field($data['photoset']['description']['_content']) : '',
        'photo_count' => isset($data['photoset']['count_photos']) ? intval($data['photoset']['count_photos']) : 0,
    ];

    // Cache the result for 6 hours
    flickr_justified_set_transient($cache_key, $photoset_info, 6 * HOUR_IN_SECONDS);

    return $photoset_info;
}

/**
 * Get photos from a Flickr set with pagination support
 *
 * @param string $user_id Flickr user ID or username
 * @param string $photoset_id Flickr photoset ID
 * @param int $page Page number (1-based)
 * @param int $per_page Photos per page (default 50, max 500)
 * @return array Array with 'photos', 'has_more', 'total', 'page', 'pages', 'album_title'
 */
function flickr_justified_get_photoset_photos_paginated($user_id, $photoset_id, $page = 1, $per_page = 50) {

    $page = max(1, (int) $page);
    $per_page = max(1, min(500, (int) $per_page)); // Flickr max is 500

    // Validate inputs
    if (empty($user_id) || empty($photoset_id) || !is_string($user_id) || !is_string($photoset_id)) {
        return flickr_justified_empty_photoset_result($page);
    }

    // Resolve username to numeric user ID if needed
    $resolved_user_id = flickr_justified_resolve_user_id($user_id);
    if (!$resolved_user_id) {
        return flickr_justified_empty_photoset_result($page);
    }

    // Cache key includes page number and version for album title feature - use resolved user ID for consistency
    $cache_key = 'flickr_justified_set_page_v2_' . md5($resolved_user_id . '_' . $photoset_id . '_' . $page . '_' . $per_page);

    // Check cache first
    $cached_result = get_transient($cache_key);
    if (!empty($cached_result) && is_array($cached_result) && isset($cached_result['photos'])) {
        flickr_justified_register_transient_key($cache_key);
        return $cached_result;
    }

    // Get API key from settings
    $api_key = flickr_justified_get_api_key();
    if (empty($api_key)) {
        return flickr_justified_empty_photoset_result($page);
    }

    // Make API call to get photos in the set
    $api_url = add_query_arg([
        'method' => 'flickr.photosets.getPhotos',
        'api_key' => $api_key,
        'photoset_id' => $photoset_id,
        'user_id' => $resolved_user_id,
        'per_page' => $per_page,
        'page' => $page,
        'extras' => 'url_m,url_l,url_o', // Get multiple size URLs
        'format' => 'json',
        'nojsoncallback' => 1,
    ], 'https://api.flickr.com/services/rest/');

    $response = wp_remote_get($api_url, [
        'timeout' => 15,
        'user-agent' => 'WordPress Flickr Justified Block'
    ]);

    if (is_wp_error($response)) {
        return flickr_justified_empty_photoset_result($page);
    }

    $response_code = (int) wp_remote_retrieve_response_code($response);
    if ($response_code < 200 || $response_code >= 300) {
        return flickr_justified_empty_photoset_result($page);
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        return flickr_justified_empty_photoset_result($page);
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return flickr_justified_empty_photoset_result($page);
    }

    // Check for API errors first
    if (isset($data['stat']) && 'fail' === $data['stat']) {
        return flickr_justified_empty_photoset_result($page);
    }

    // Check if photoset data exists and has photos
    if (!isset($data['photoset']) || empty($data['photoset']['photo']) || !is_array($data['photoset']['photo'])) {
        return flickr_justified_empty_photoset_result($page);
    }

    // Extract pagination info
    $total_photos = isset($data['photoset']['total']) ? (int) $data['photoset']['total'] : 0;
    $current_page = isset($data['photoset']['page']) ? (int) $data['photoset']['page'] : $page;
    $total_pages = isset($data['photoset']['pages']) ? (int) $data['photoset']['pages'] : 1;

    // Get album title using separate API call (only for first page to avoid redundant calls)
    $album_title = '';
    if (1 === $page) {
        $photoset_info = flickr_justified_get_photoset_info($user_id, $photoset_id);
        if (!empty($photoset_info) && !empty($photoset_info['title'])) {
            $album_title = $photoset_info['title'];
        }
    }

    // Convert photos to individual photo page URLs
    $photo_urls = [];
    foreach ($data['photoset']['photo'] as $photo) {
        if (empty($photo['id']) || !is_string($photo['id'])) {
            continue;
        }

        $photo_id = preg_replace('/[^0-9]/', '', $photo['id']);
        if (empty($photo_id)) {
            continue;
        }

        // Create the standard photo page URL format that our existing functions can handle
        $photo_url = 'https://flickr.com/photos/' . rawurlencode($user_id) . '/' . $photo_id . '/';
        $photo_urls[] = $photo_url;
    }

    $result = [
        'photos' => $photo_urls,
        'has_more' => $current_page < $total_pages,
        'total' => $total_photos,
        'page' => $current_page,
        'pages' => $total_pages,
        'album_title' => $album_title,
    ];

    if (!empty($photo_urls)) {
        // Cache the results (shorter cache for paginated results)
        $cache_duration = HOUR_IN_SECONDS * 6; // 6 hours for individual pages
        $configured_duration = (int) flickr_justified_get_admin_setting('get_cache_duration', 0);
        if ($configured_duration > 0) {
            $cache_duration = max(HOUR_IN_SECONDS, (int) floor($configured_duration / 4)); // 1/4 of main cache duration, min 1 hour
        }
        flickr_justified_set_transient($cache_key, $result, $cache_duration);
    }

    return $result;
}

/**
 * Retrieve all photos from a Flickr set, aggregating across all pages.
 *
 * @param string $user_id Flickr user ID or username
 * @param string $photoset_id Flickr photoset ID
 * @return array Array with 'photos', 'has_more', 'total', 'page', 'pages', 'album_title'
 */
function flickr_justified_get_full_photoset_photos($user_id, $photoset_id) {

    $empty_result = flickr_justified_empty_photoset_result(1);

    if (empty($user_id) || empty($photoset_id) || !is_string($user_id) || !is_string($photoset_id)) {
        return $empty_result;
    }

    $resolved_user_id = flickr_justified_resolve_user_id($user_id);
    if (!$resolved_user_id) {
        return $empty_result;
    }

    $cache_key = 'flickr_justified_set_full_' . md5($resolved_user_id . '_' . $photoset_id);
    $cached_result = get_transient($cache_key);
    if (!empty($cached_result) && is_array($cached_result) && isset($cached_result['photos'])) {
        flickr_justified_register_transient_key($cache_key);
        return $cached_result;
    }

    $per_page = 500; // Flickr maximum per page
    $page = 1;
    $all_photos = [];
    $total_pages = 1;
    $total_photos = 0;
    $album_title = '';
    $last_has_more = false;
    $pages_fetched = 0;

    while (true) {
        $page_result = flickr_justified_get_photoset_photos_paginated($user_id, $photoset_id, $page, $per_page);

        if (isset($page_result['album_title']) && '' === $album_title && is_string($page_result['album_title'])) {
            $album_title = $page_result['album_title'];
        }

        if (empty($page_result['photos']) || !is_array($page_result['photos'])) {
            $last_has_more = !empty($page_result['has_more']);
            break;
        }

        if (isset($page_result['pages'])) {
            $total_pages = max(1, (int) $page_result['pages']);
        }

        if (isset($page_result['total'])) {
            $total_photos = max($total_photos, (int) $page_result['total']);
        }

        $all_photos = array_merge($all_photos, array_values($page_result['photos']));
        $pages_fetched++;

        $last_has_more = !empty($page_result['has_more']);
        if (!$last_has_more) {
            break;
        }

        $next_page = isset($page_result['page']) ? ((int) $page_result['page']) + 1 : $page + 1;
        if ($next_page <= $page) {
            // Prevent infinite loops if API returns unexpected page values
            break;
        }

        $page = $next_page;

        if ($page > $total_pages) {
            // Stop if we've already fetched the reported number of pages
            break;
        }
    }

    $loaded_photos_count = count($all_photos);

    $full_result = [
        'photos' => $all_photos,
        'has_more' => (bool) $last_has_more,
        'total' => $total_photos > 0 ? $total_photos : $loaded_photos_count,
        'page' => 1,
        'pages' => max(1, $total_pages),
        'album_title' => $album_title,
    ];
    $expected_pages = max(1, (int) $total_pages);
    $fetched_all_pages = false;

    if ($pages_fetched > 0) {
        if ($total_photos > 0 && $loaded_photos_count >= $total_photos) {
            $fetched_all_pages = true;
        } elseif (!$last_has_more && $pages_fetched >= $expected_pages) {
            $fetched_all_pages = true;
        }
    }

    if (!$full_result['has_more'] && $fetched_all_pages && !empty($all_photos)) {
        $cache_duration = 6 * HOUR_IN_SECONDS;
        $configured_duration = (int) flickr_justified_get_admin_setting('get_cache_duration', 0);
        if ($configured_duration > 0) {
            $cache_duration = max(HOUR_IN_SECONDS, (int) $configured_duration);
        }

        flickr_justified_set_transient($cache_key, $full_result, $cache_duration);
    }

    return $full_result;
}


/**
 * Render with justified gallery layout
 */
function flickr_justified_render_justified_gallery($photos, $block_id, $gap, $image_size, $responsive_settings, $row_height_mode, $row_height, $max_viewport_height, $single_image_alignment, $set_metadata = [], $context = []) {

    $photo_limit = isset($context['photo_limit']) ? max(0, (int) $context['photo_limit']) : 0;
    $sort_order = isset($context['sort_order']) && 'views_desc' === $context['sort_order'] ? 'views_desc' : 'input';
    $loaded_count = is_array($photos) ? count($photos) : 0;

    // Get admin breakpoints
    $breakpoints = flickr_justified_get_admin_setting('get_breakpoints', []);

    // Get attribution text for consistent PhotoSwipe button labeling
    $attribution_text = flickr_justified_get_admin_setting('get_attribution_text', 'Flickr');

    // Generate simple structure - JavaScript will organize into responsive rows
    $responsive_attr = esc_attr(flickr_justified_encode_json_attr($responsive_settings));
    $breakpoints_attr = esc_attr(flickr_justified_encode_json_attr($breakpoints));
    $set_metadata_attr = !empty($set_metadata) ? esc_attr(flickr_justified_encode_json_attr($set_metadata)) : '';
    $output = sprintf(
        '<div id="%s" class="flickr-justified-grid" style="--gap: %dpx;" data-responsive-settings="%s" data-breakpoints="%s" data-row-height-mode="%s" data-row-height="%d" data-max-viewport-height="%d" data-single-image-alignment="%s" data-use-builtin-lightbox="%s" data-set-metadata="%s" data-attribution-text="%s" data-photo-limit="%d" data-sort-order="%s" data-loaded-count="%d">',
        esc_attr($block_id),
        (int) $gap,
        $responsive_attr,
        $breakpoints_attr,
        esc_attr($row_height_mode),
        (int) $row_height,
        (int) $max_viewport_height,
        esc_attr($single_image_alignment),
        '1',
        $set_metadata_attr,
        esc_attr($attribution_text),
        (int) $photo_limit,
        esc_attr($sort_order),
        (int) $loaded_count
    );

    if (!is_array($photos)) {
        $photos = [];
    }

    foreach ($photos as $photo) {
        if (is_string($photo)) {
            $photo = ['url' => $photo];
        }

        $url = isset($photo['url']) ? esc_url($photo['url']) : '';
        if ('' === $url) {
            continue;
        }

        $is_flickr = $photo['is_flickr'] ?? flickr_justified_is_flickr_photo_url($url);
        $position = isset($photo['position']) ? (int) $photo['position'] : null;
        $stats = [];
        $attribution_page_url = isset($photo['attribution_url']) ? esc_url($photo['attribution_url']) : $url;
        if ('' === $attribution_page_url) {
            $attribution_page_url = $url;
        }

        if ($is_flickr) {
            $available_sizes = flickr_justified_get_available_flickr_sizes(true);

            $image_data = flickr_justified_get_flickr_image_sizes_with_dimensions($url, $available_sizes, true);

            if (!empty($photo['stats']) && is_array($photo['stats'])) {
                $stats = $photo['stats'];
                if (is_array($image_data) && !isset($image_data['_stats'])) {
                    $image_data['_stats'] = $stats;
                }
            } elseif (!empty($image_data['_stats']) && is_array($image_data['_stats'])) {
                $stats = $image_data['_stats'];
            }

            $rotation = 0;
            if (isset($photo['rotation'])) {
                $rotation = flickr_justified_normalize_rotation($photo['rotation']);
            } elseif (isset($image_data['_rotation'])) {
                $rotation = flickr_justified_normalize_rotation($image_data['_rotation']);
            } elseif (!empty($image_data['_photo_info'])) {
                $rotation = flickr_justified_extract_rotation_from_info($image_data['_photo_info']);
            }

            $display_src = isset($image_data[$image_size]['url']) ? $image_data[$image_size]['url'] : '';
            $dimensions = isset($image_data[$image_size]) ? $image_data[$image_size] : null;

            // For PhotoSwipe, select size appropriate for high-res displays (around 2-3x screen width)
            $best_lightbox_size = flickr_justified_select_best_size($image_data, 3500, 3500);

            if ($best_lightbox_size && isset($image_data[$best_lightbox_size])) {
                $selected_width = $image_data[$best_lightbox_size]['width'];
                if ($selected_width < 3000) {
                    $best_lightbox_size = flickr_justified_select_best_size($image_data, PHP_INT_MAX, PHP_INT_MAX);
                }
            } else {
                $best_lightbox_size = flickr_justified_select_best_size($image_data, PHP_INT_MAX, PHP_INT_MAX);
            }

            $lightbox_src = '';
            $lightbox_dimensions = null;
            if ($best_lightbox_size && isset($image_data[$best_lightbox_size]['url'])) {
                $lightbox_src = $image_data[$best_lightbox_size]['url'];
                $lightbox_dimensions = $image_data[$best_lightbox_size];
            }

            if (empty($display_src) && !empty($lightbox_src)) {
                $display_src = $lightbox_src;
                $dimensions = $lightbox_dimensions;
            }
            if (empty($lightbox_src) && !empty($display_src)) {
                $lightbox_src = $display_src;
            }

            if (empty($display_src)) {
                $error_mode = flickr_justified_get_admin_setting('get_privacy_error_mode', 'show_placeholder');

                if ($error_mode === 'show_nothing') {
                    continue;
                }

                $error_message = 'Photo unavailable';
                $output .= sprintf(
                    '<article class="flickr-card flickr-error">
                        <div style="
                            padding: 20px;
                            background: #f8d7da;
                            border: 1px solid #f5c6cb;
                            border-radius: 4px;
                            color: #721c24;
                            text-align: center;
                            min-height: 100px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                        ">%s</div>
                    </article>',
                    esc_html($error_message)
                );
                continue;
            }

            $data_attrs = '';
            $rotated_display_dimensions = flickr_justified_apply_rotation_to_dimensions($dimensions ?? [], $rotation);
            $rotated_lightbox_dimensions = flickr_justified_apply_rotation_to_dimensions($lightbox_dimensions ?? [], $rotation);

            $width_attr = null;
            $height_attr = null;
            if (isset($rotated_lightbox_dimensions['width'], $rotated_lightbox_dimensions['height']) &&
                $rotated_lightbox_dimensions['width'] > 0 && $rotated_lightbox_dimensions['height'] > 0) {
                $width_attr = (int) $rotated_lightbox_dimensions['width'];
                $height_attr = (int) $rotated_lightbox_dimensions['height'];
            } elseif (isset($rotated_display_dimensions['width'], $rotated_display_dimensions['height']) &&
                $rotated_display_dimensions['width'] > 0 && $rotated_display_dimensions['height'] > 0) {
                $width_attr = (int) $rotated_display_dimensions['width'];
                $height_attr = (int) $rotated_display_dimensions['height'];
            }

            if (null !== $width_attr && null !== $height_attr) {
                $data_attrs = sprintf(' data-width="%d" data-height="%d"', $width_attr, $height_attr);
            }

            if ($rotation) {
                $data_attrs .= sprintf(' data-rotation="%d"', (int) $rotation);
            }

            $lightbox_class = 'flickr-builtin-lightbox';
            $gallery_group_attribute = 'data-gallery';
            $gallery_group = esc_attr($block_id);

            $attribution_attrs = sprintf(' data-flickr-page="%s" data-flickr-attribution-text="%s"',
                esc_attr($attribution_page_url),
                esc_attr($attribution_text)
            );

            $attribution_attrs .= sprintf(' data-caption="%s" data-title="%s" title="%s"',
                esc_attr($attribution_text),
                esc_attr($attribution_text),
                esc_attr($attribution_text)
            );

            $views = isset($stats['views']) ? (int) $stats['views'] : 0;
            $comments = isset($stats['comments']) ? (int) $stats['comments'] : 0;
            $favorites = isset($stats['favorites']) ? (int) $stats['favorites'] : 0;

            $card_attributes = ['class="flickr-card"', 'style="position: relative;"'];
            if ($rotation) {
                $card_attributes[] = 'data-rotation="' . esc_attr($rotation) . '"';
            }
            if (null !== $position) {
                $card_attributes[] = 'data-position="' . esc_attr($position) . '"';
            }
            $card_attributes[] = 'data-views="' . esc_attr($views) . '"';
            $card_attributes[] = 'data-comments="' . esc_attr($comments) . '"';
            $card_attributes[] = 'data-favorites="' . esc_attr($favorites) . '"';

            if (null !== $width_attr && null !== $height_attr) {
                $card_attributes[] = 'data-width="' . esc_attr($width_attr) . '"';
                $card_attributes[] = 'data-height="' . esc_attr($height_attr) . '"';
            }

            $img_extra_attrs = [];
            if (null !== $width_attr && null !== $height_attr) {
                $img_extra_attrs[] = 'data-width="' . esc_attr($width_attr) . '"';
                $img_extra_attrs[] = 'data-height="' . esc_attr($height_attr) . '"';
            }

            if ($rotation) {
                $img_extra_attrs[] = 'data-rotation="' . esc_attr($rotation) . '"';
                $img_extra_attrs[] = 'style="transform: rotate(' . esc_attr($rotation) . 'deg); transform-origin: center center;"';
            }

            $img_attr_string = '';
            if (!empty($img_extra_attrs)) {
                $img_attr_string = ' ' . implode(' ', $img_extra_attrs);
            }

            $output .= sprintf(
                '<article %s>
                    <a href="%s" class="%s" %s="%s" %s%s>
                        <img src="%s" loading="lazy" decoding="async" alt=""%s>
                    </a>
                </article>',
                implode(' ', $card_attributes),
                esc_url($lightbox_src),
                esc_attr($lightbox_class),
                esc_attr($gallery_group_attribute),
                esc_attr($gallery_group),
                $data_attrs,
                $attribution_attrs,
                esc_url($display_src),
                $img_attr_string
            );
        } else {
            $views = isset($photo['views']) ? (int) $photo['views'] : 0;
            $comments = isset($photo['comments']) ? (int) $photo['comments'] : 0;
            $favorites = isset($photo['favorites']) ? (int) $photo['favorites'] : 0;

            $card_attributes = ['class="flickr-card"', 'style="position: relative;"'];
            if (null !== $position) {
                $card_attributes[] = 'data-position="' . esc_attr($position) . '"';
            }
            $card_attributes[] = 'data-views="' . esc_attr($views) . '"';
            $card_attributes[] = 'data-comments="' . esc_attr($comments) . '"';
            $card_attributes[] = 'data-favorites="' . esc_attr($favorites) . '"';

            $lightbox_class = 'flickr-builtin-lightbox';
            $gallery_group_attribute = 'data-gallery';
            $gallery_group = esc_attr($block_id);

            $output .= sprintf(
                '<article %s>
                    <a href="%s" class="%s" %s="%s">
                        <img src="%s" loading="lazy" decoding="async" alt="">
                    </a>
                </article>',
                implode(' ', $card_attributes),
                esc_url($url),
                esc_attr($lightbox_class),
                esc_attr($gallery_group_attribute),
                esc_attr($gallery_group),
                esc_url($url)
            );
        }
    }

    $output .= '</div>';
    return $output;
}

/**
 * Render the Flickr Justified block
 *
 * @param array $attributes Block attributes
 * @return string Block HTML output
 */
function flickr_justified_render_block($attributes) {
    $urls = isset($attributes['urls']) ? trim($attributes['urls']) : '';
    $gap = isset($attributes['gap']) ? max(0, (int) $attributes['gap']) : 12;
    $image_size = isset($attributes['imageSize']) ? $attributes['imageSize'] : 'large';
    // PhotoSwipe automatically selects optimal image sizes
    // Get configured default responsive settings from admin, with fallback
    $default_responsive = flickr_justified_get_admin_setting('get_configured_default_responsive_settings', []);
    if (empty($default_responsive)) {
        $default_responsive = [
            'mobile' => 1,
            'mobile_landscape' => 1,
            'tablet_portrait' => 2,
            'tablet_landscape' => 3,
            'desktop' => 3,
            'large_desktop' => 4,
            'extra_large' => 4
        ];
    }

    $responsive_settings = isset($attributes['responsiveSettings']) ? $attributes['responsiveSettings'] : $default_responsive;
    $row_height_mode = isset($attributes['rowHeightMode']) ? $attributes['rowHeightMode'] : 'auto';
    $row_height = isset($attributes['rowHeight']) ? max(120, min(500, (int) $attributes['rowHeight'])) : 280;
    $max_viewport_height = isset($attributes['maxViewportHeight']) ? max(30, min(100, (int) $attributes['maxViewportHeight'])) : 80;
    $single_image_alignment = isset($attributes['singleImageAlignment']) ? $attributes['singleImageAlignment'] : 'center';

    if (empty($urls)) {
        return '';
    }

    // Split URLs by lines and clean them, then handle multiple URLs on same line
    $url_lines = array_filter(array_map('trim', preg_split('/\R/u', $urls)));

    // Further split any lines that contain multiple URLs (common when copy-pasting)
    $final_urls = [];
    foreach ($url_lines as $line) {
        // Check if line contains multiple URLs by looking for http/https patterns
        if (preg_match_all('/https?:\/\/[^\s]+/i', $line, $matches)) {
            foreach ($matches[0] as $url) {
                $final_urls[] = trim($url);
            }
        } else if (!empty($line)) {
            // Single URL or non-URL content
            $final_urls[] = $line;
        }
    }
    $url_lines = array_filter($final_urls);

    if (empty($url_lines)) {
        return '';
    }

    $max_photos = isset($attributes['maxPhotos']) ? max(0, (int) $attributes['maxPhotos']) : 0;
    $sort_order = isset($attributes['sortOrder']) ? $attributes['sortOrder'] : 'input';
    if (!in_array($sort_order, ['input', 'views_desc'], true)) {
        $sort_order = 'input';
    }

    $needs_stats = ('views_desc' === $sort_order);
    $remaining_limit = ($max_photos > 0 && 'views_desc' !== $sort_order) ? $max_photos : null;
    $photo_items = [];
    $set_metadata = [];
    $position_counter = 0;

    foreach ($url_lines as $url) {
        if (null !== $remaining_limit && $remaining_limit <= 0) {
            break;
        }

        $set_info = flickr_justified_parse_set_url($url);
        if ($set_info) {
            if ('views_desc' === $sort_order) {
                $set_result = flickr_justified_get_full_photoset_photos($set_info['user_id'], $set_info['photoset_id']);
            } else {
                $per_page = 50;
                if (null !== $remaining_limit) {
                    $per_page = max(1, min(50, $remaining_limit));
                }

                $set_result = flickr_justified_get_photoset_photos_paginated($set_info['user_id'], $set_info['photoset_id'], 1, $per_page);
            }
            $set_photos = isset($set_result['photos']) && is_array($set_result['photos']) ? $set_result['photos'] : [];

            if ('views_desc' !== $sort_order && null !== $remaining_limit) {
                $set_photos = array_slice($set_photos, 0, $remaining_limit);
            }

            $added_count = 0;
            foreach ($set_photos as $photo_url) {
                if (null !== $remaining_limit && $remaining_limit <= 0) {
                    break;
                }

                $photo_url = trim($photo_url);
                if ('' === $photo_url) {
                    continue;
                }

                $is_flickr = flickr_justified_is_flickr_photo_url($photo_url);
                $attribution_url = $photo_url;
                if ($is_flickr) {
                    $album_attribution_url = flickr_justified_build_album_photo_attribution_url(
                        $photo_url,
                        $set_info['photoset_id'],
                        $set_info['user_id']
                    );
                    if (!empty($album_attribution_url)) {
                        $attribution_url = $album_attribution_url;
                    }
                }
                $item = [
                    'url' => $photo_url,
                    'is_flickr' => $is_flickr,
                    'position' => $position_counter,
                    'views' => 0,
                    'comments' => 0,
                    'favorites' => 0,
                    'attribution_url' => $attribution_url,
                ];

                if ($needs_stats && $is_flickr) {
                    $photo_id = flickr_justified_extract_photo_id($photo_url);
                    if ($photo_id) {
                        $stats = flickr_justified_get_photo_stats($photo_id);
                        if (!empty($stats) && is_array($stats)) {
                            $item['stats'] = $stats;
                            $item['views'] = isset($stats['views']) ? (int) $stats['views'] : 0;
                            $item['comments'] = isset($stats['comments']) ? (int) $stats['comments'] : 0;
                            $item['favorites'] = isset($stats['favorites']) ? (int) $stats['favorites'] : 0;
                        }
                    }
                }

                $photo_items[] = $item;
                $position_counter++;
                $added_count++;

                if (null !== $remaining_limit) {
                    $remaining_limit--;
                }
            }

            if (0 === $added_count) {
                $photo_items[] = [
                    'url' => $url,
                    'is_flickr' => false,
                    'position' => $position_counter,
                    'views' => 0,
                    'comments' => 0,
                    'favorites' => 0,
                    'attribution_url' => $url,
                ];
                $position_counter++;

                if (null !== $remaining_limit) {
                    $remaining_limit--;
                }

                continue;
            }

            if ($added_count > 0) {
                $has_more = !empty($set_result['has_more']);
                if (null !== $remaining_limit && $remaining_limit <= 0) {
                    $has_more = false;
                }

                $set_metadata[] = [
                    'user_id' => $set_info['user_id'],
                    'photoset_id' => $set_info['photoset_id'],
                    'current_page' => 1,
                    'total_pages' => isset($set_result['pages']) ? (int) $set_result['pages'] : 1,
                    'total_photos' => isset($set_result['total']) ? (int) $set_result['total'] : 0,
                    'loaded_photos' => $added_count,
                    'has_more' => $has_more,
                    'sort_order' => $sort_order,
                    'max_photos' => $max_photos,
                ];
            }

            if (null !== $remaining_limit && $remaining_limit <= 0) {
                break;
            }

            continue;
        }

        $url = trim($url);
        if ('' === $url) {
            continue;
        }

        $is_flickr = flickr_justified_is_flickr_photo_url($url);
        $item = [
            'url' => $url,
            'is_flickr' => $is_flickr,
            'position' => $position_counter,
            'views' => 0,
            'comments' => 0,
            'favorites' => 0,
            'attribution_url' => $url,
        ];

        if ($needs_stats && $is_flickr) {
            $photo_id = flickr_justified_extract_photo_id($url);
            if ($photo_id) {
                $stats = flickr_justified_get_photo_stats($photo_id);
                if (!empty($stats) && is_array($stats)) {
                    $item['stats'] = $stats;
                    $item['views'] = isset($stats['views']) ? (int) $stats['views'] : 0;
                    $item['comments'] = isset($stats['comments']) ? (int) $stats['comments'] : 0;
                    $item['favorites'] = isset($stats['favorites']) ? (int) $stats['favorites'] : 0;
                }
            }
        }

        $photo_items[] = $item;
        $position_counter++;

        if (null !== $remaining_limit) {
            $remaining_limit--;
        }
    }

    if (empty($photo_items)) {
        return '';
    }

    if ('views_desc' === $sort_order) {
        usort($photo_items, static function ($a, $b) {
            $views_a = isset($a['views']) ? (int) $a['views'] : 0;
            $views_b = isset($b['views']) ? (int) $b['views'] : 0;

            if ($views_a === $views_b) {
                $pos_a = isset($a['position']) ? (int) $a['position'] : 0;
                $pos_b = isset($b['position']) ? (int) $b['position'] : 0;
                return $pos_a <=> $pos_b;
            }

            return $views_b <=> $views_a;
        });
    }

    if ($max_photos > 0 && count($photo_items) > $max_photos) {
        $photo_items = array_slice($photo_items, 0, $max_photos);
    }

    // Generate unique ID for this block instance
    $block_id = 'flickr-justified-' . uniqid();

    return flickr_justified_render_justified_gallery(
        $photo_items,
        $block_id,
        $gap,
        $image_size,
        $responsive_settings,
        $row_height_mode,
        $row_height,
        $max_viewport_height,
        $single_image_alignment,
        $set_metadata,
        [
            'photo_limit' => $max_photos,
            'sort_order' => $sort_order,
        ]
    );
}
