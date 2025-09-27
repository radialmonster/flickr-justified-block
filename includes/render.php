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
        ];
    }

    return $size_mapping;
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
 */
function flickr_justified_get_flickr_image_sizes_with_dimensions($page_url, $requested_sizes = ['large', 'original']) {
    if (!preg_match('#flickr\.com/photos/[^/]+/(\d+)#', $page_url, $matches)) {
        return [];
    }

    $photo_id = $matches[1];
    $cache_key = 'flickr_justified_dims_' . $photo_id . '_' . md5(implode(',', $requested_sizes));

    // Check cache first
    $cached_result = get_transient($cache_key);
    if (!empty($cached_result) && is_array($cached_result)) {
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
        $stats = flickr_justified_get_photo_stats($photo_id);
        if (!empty($stats)) {
            $result['_stats'] = $stats;
        }

        // Cache the results
        $cache_duration = (int) flickr_justified_get_admin_setting('get_cache_duration', WEEK_IN_SECONDS);
        if ($cache_duration <= 0) {
            $cache_duration = WEEK_IN_SECONDS;
        }
        set_transient($cache_key, $result, $cache_duration);
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
 * Retrieve view, comment, and favorite counts for a Flickr photo.
 *
 * @param string $photo_id Flickr photo ID.
 * @param string|null $date Date string (YYYY-MM-DD). Defaults to current day in UTC.
 * @return array Associative array with views, comments, and favorites (empty on failure).
 */
function flickr_justified_get_photo_stats($photo_id, $date = null) {
    $photo_id = trim((string) $photo_id);

    if ($photo_id === '') {
        return [];
    }

    if (null === $date) {
        $date = gmdate('Y-m-d');
    }

    $cache_key = 'flickr_justified_photo_stats_' . $photo_id . '_' . md5($date);
    $cached_stats = get_transient($cache_key);
    if (is_array($cached_stats)) {
        return $cached_stats;
    }

    $api_key = flickr_justified_get_api_key();
    if (empty($api_key)) {
        return [];
    }

    $query_args = [
        'method' => 'flickr.stats.getPhotoStats',
        'api_key' => $api_key,
        'photo_id' => $photo_id,
        'date' => $date,
        'format' => 'json',
        'nojsoncallback' => 1,
    ];

    /**
     * Filter the Flickr photo stats API request query arguments.
     *
     * This allows advanced users to inject authentication tokens or signatures
     * required for private stats endpoints.
     *
     * @param array $query_args Request query arguments.
     * @param string $photo_id Flickr photo ID.
     * @param string $date Date string (YYYY-MM-DD).
     */
    $query_args = apply_filters('flickr_justified_photo_stats_query_args', $query_args, $photo_id, $date);

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

    if (empty($data['stats']) || !is_array($data['stats'])) {
        return [];
    }

    $stats = [
        'views' => isset($data['stats']['views']) ? (int) $data['stats']['views'] : 0,
        'comments' => isset($data['stats']['comments']) ? (int) $data['stats']['comments'] : 0,
        'favorites' => isset($data['stats']['favorites']) ? (int) $data['stats']['favorites'] : 0,
        'date' => $date,
    ];

    $cache_duration = (int) flickr_justified_get_admin_setting('get_cache_duration', WEEK_IN_SECONDS);
    if ($cache_duration <= 0) {
        $cache_duration = WEEK_IN_SECONDS;
    }
    set_transient($cache_key, $stats, $cache_duration);

    return $stats;
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
    set_transient($cache_key, $user_id, DAY_IN_SECONDS);

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
    set_transient($cache_key, $photoset_info, 6 * HOUR_IN_SECONDS);

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
        set_transient($cache_key, $result, $cache_duration);
    }

    return $result;
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

        if ($is_flickr) {
            $available_sizes = [
                'original', 'large6k', 'large5k', 'largef', 'large4k', 'large3k',
                'large2048', 'large1600', 'large1024', 'large',
                'medium800', 'medium640', 'medium500', 'medium',
                'small400', 'small320', 'small240',
                'thumbnail100', 'thumbnail150s', 'thumbnail75s'
            ];

            $image_data = flickr_justified_get_flickr_image_sizes_with_dimensions($url, $available_sizes);

            if (!empty($photo['stats']) && is_array($photo['stats'])) {
                $stats = $photo['stats'];
                if (is_array($image_data) && !isset($image_data['_stats'])) {
                    $image_data['_stats'] = $stats;
                }
            } elseif (!empty($image_data['_stats']) && is_array($image_data['_stats'])) {
                $stats = $image_data['_stats'];
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
            if ($best_lightbox_size && isset($image_data[$best_lightbox_size]['url'])) {
                $lightbox_src = $image_data[$best_lightbox_size]['url'];
            }

            if (empty($display_src) && !empty($lightbox_src)) {
                $display_src = $lightbox_src;
                $dimensions = isset($image_data[$best_lightbox_size]) ? $image_data[$best_lightbox_size] : null;
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
            $lightbox_dimensions = null;
            if ($best_lightbox_size && isset($image_data[$best_lightbox_size])) {
                $lightbox_dimensions = $image_data[$best_lightbox_size];
            }

            if ($lightbox_dimensions) {
                $data_attrs = sprintf(' data-width="%d" data-height="%d"', $lightbox_dimensions['width'], $lightbox_dimensions['height']);
            }

            $lightbox_class = 'flickr-builtin-lightbox';
            $gallery_group_attribute = 'data-gallery';
            $gallery_group = esc_attr($block_id);

            $attribution_attrs = sprintf(' data-flickr-page="%s" data-flickr-attribution-text="%s"',
                esc_attr($url),
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
            if (null !== $position) {
                $card_attributes[] = 'data-position="' . esc_attr($position) . '"';
            }
            $card_attributes[] = 'data-views="' . esc_attr($views) . '"';
            $card_attributes[] = 'data-comments="' . esc_attr($comments) . '"';
            $card_attributes[] = 'data-favorites="' . esc_attr($favorites) . '"';

            $output .= sprintf(
                '<article %s>
                    <a href="%s" class="%s" %s="%s" %s%s>
                        <img src="%s" loading="lazy" decoding="async" alt="">
                    </a>
                </article>',
                implode(' ', $card_attributes),
                esc_url($lightbox_src),
                esc_attr($lightbox_class),
                esc_attr($gallery_group_attribute),
                esc_attr($gallery_group),
                $data_attrs,
                $attribution_attrs,
                esc_url($display_src)
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
    $remaining_limit = $max_photos > 0 ? $max_photos : null;
    $photo_items = [];
    $set_metadata = [];
    $position_counter = 0;

    foreach ($url_lines as $url) {
        if (null !== $remaining_limit && $remaining_limit <= 0) {
            break;
        }

        $set_info = flickr_justified_parse_set_url($url);
        if ($set_info) {
            $per_page = 50;
            if (null !== $remaining_limit) {
                $per_page = max(1, min(50, $remaining_limit));
            }

            $set_result = flickr_justified_get_photoset_photos_paginated($set_info['user_id'], $set_info['photoset_id'], 1, $per_page);
            $set_photos = isset($set_result['photos']) && is_array($set_result['photos']) ? $set_result['photos'] : [];

            if (null !== $remaining_limit) {
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
                $item = [
                    'url' => $photo_url,
                    'is_flickr' => $is_flickr,
                    'position' => $position_counter,
                    'views' => 0,
                    'comments' => 0,
                    'favorites' => 0,
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
