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
 * Fallback: Map API sizes directly to requested sizes (when direct URL construction fails)
 */
function flickr_justified_map_api_sizes_to_requested($api_sizes, $requested_sizes) {
    $size_mapping = [
        'original' => ['Original'],
        'large6k' => ['Large 6144', 'Original'],
        'large5k' => ['Large 5120', 'Large 6144', 'Original'],
        'largef' => ['Large 4096', 'Large 5120', 'Original'],
        'large4k' => ['Large 4096', 'Large 5120', 'Original'],
        'large3k' => ['Large 3072', 'Large 4096', 'Original'],
        'large2048' => ['Large 2048', 'Large 3072', 'Original'],
        'large1600' => ['Large 1600', 'Large 2048', 'Original'],
        'large1024' => ['Large 1024', 'Large 1600', 'Original'],
        'large' => ['Large', 'Large 1024', 'Original'],
        'medium800' => ['Medium 800', 'Large', 'Original'],
        'medium640' => ['Medium 640', 'Medium 800', 'Large'],
        'medium500' => ['Medium', 'Medium 640', 'Large'],
        'medium' => ['Medium', 'Medium 640', 'Large'],
        'small400' => ['Small 400', 'Medium'],
        'small320' => ['Small 320', 'Small 400', 'Medium'],
        'small240' => ['Small', 'Small 320', 'Medium']
    ];

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
    $api_key = '';
    if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_api_key')) {
        $api_key = FlickrJustifiedAdminSettings::get_api_key();
    }

    if (empty($api_key)) {
        // Debug: Log when API key is missing
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: No API key found for photo ID: ' . $photo_id);
        }
        return [];
    }

    // Debug: Log that we're making an API call
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Flickr Justified Block: Making API call for photo ID: ' . $photo_id);
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: API request error for photo ID ' . $photo_id . ': ' . $response->get_error_message());
        }
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data['sizes']['size'])) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: No sizes data returned for photo ID ' . $photo_id . '. Response: ' . $body);
        }
        return [];
    }

    // Debug: Log successful API call
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Flickr Justified Block: Successfully retrieved ' . count($data['sizes']['size']) . ' sizes for photo ID: ' . $photo_id);
    }

    // Build result with URLs and dimensions
    $result = flickr_justified_map_api_sizes_to_requested_with_dims($data['sizes']['size'], $requested_sizes);

    if (!empty($result)) {
        // Cache the results
        $cache_duration = WEEK_IN_SECONDS;
        if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_cache_duration')) {
            $cache_duration = FlickrJustifiedAdminSettings::get_cache_duration();
        }
        set_transient($cache_key, $result, $cache_duration);
    }

    return $result;
}

/**
 * Map API sizes to requested sizes including dimensions
 */
function flickr_justified_map_api_sizes_to_requested_with_dims($api_sizes, $requested_sizes) {
    $size_mapping = [
        'original' => ['Original'],
        'large6k' => ['Large 6144', 'Original'],
        'large5k' => ['Large 5120', 'Large 6144', 'Original'],
        'largef' => ['Large 4096', 'Large 5120', 'Original'],
        'large4k' => ['Large 4096', 'Large 5120', 'Original'],
        'large3k' => ['Large 3072', 'Large 4096', 'Original'],
        'large2048' => ['Large 2048', 'Large 3072', 'Original'],
        'large1600' => ['Large 1600', 'Large 2048', 'Original'],
        'large1024' => ['Large 1024', 'Large 1600', 'Original'],
        'large' => ['Large', 'Large 1024', 'Original'],
        'medium800' => ['Medium 800', 'Large', 'Original'],
        'medium640' => ['Medium 640', 'Medium 800', 'Large'],
        'medium500' => ['Medium', 'Medium 640', 'Large'],
        'medium' => ['Medium', 'Medium 640', 'Large'],
        'small400' => ['Small 400', 'Medium'],
        'small320' => ['Small 320', 'Small 400', 'Medium'],
        'small240' => ['Small', 'Small 320', 'Medium']
    ];

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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: flickr_justified_parse_set_url - Invalid URL: ' . var_export($url, true));
        }
        return false;
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Flickr Justified Block: flickr_justified_parse_set_url - Testing URL: ' . $url);
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: Testing pattern ' . $index . ': ' . $pattern . ' against URL: ' . $url);
        }

        if (preg_match($pattern, $url, $matches)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flickr Justified Block: Pattern ' . $index . ' MATCHED! Matches: ' . json_encode($matches));
            }

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
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('Flickr Justified Block: Found /with/ parameter - photo ID: ' . $matches[3]);
                    }
                }

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Flickr Justified Block: Successfully parsed set URL - Result: ' . json_encode($result));
                }

                return $result;
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flickr Justified Block: Pattern ' . $index . ' did not match');
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
    $result = flickr_justified_get_photoset_photos_paginated($user_id, $photoset_id, 1, 500);
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
    $api_key = '';
    if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_api_key')) {
        $api_key = FlickrJustifiedAdminSettings::get_api_key();
    }

    if (empty($api_key)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: No API key found for user lookup: ' . $username);
        }
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: User lookup failed: ' . $response->get_error_message());
        }
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['user']['id'])) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: User lookup returned invalid data for: ' . $username . ' - Response: ' . $body);
        }
        return false;
    }

    $user_id = $data['user']['id'];

    // Cache the result for 24 hours
    set_transient($cache_key, $user_id, DAY_IN_SECONDS);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Flickr Justified Block: Resolved username "' . $username . '" to user ID: ' . $user_id);
    }

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
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Flickr Justified Block: flickr_justified_get_photoset_info called for user: ' . $user_id . ', photoset: ' . $photoset_id);
    }

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
    $api_key = '';
    if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_api_key')) {
        $api_key = FlickrJustifiedAdminSettings::get_api_key();
    }

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

    $body = wp_remote_retrieve_body($response);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Flickr Justified Block: photosets.getInfo API response: ' . $body);
    }

    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['photoset'])) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: photosets.getInfo JSON error or no photoset data. Error: ' . json_last_error_msg());
        }
        return false;
    }

    $photoset_info = [
        'title' => isset($data['photoset']['title']['_content']) ? sanitize_text_field($data['photoset']['title']['_content']) : '',
        'description' => isset($data['photoset']['description']['_content']) ? sanitize_textarea_field($data['photoset']['description']['_content']) : '',
        'photo_count' => isset($data['photoset']['count_photos']) ? intval($data['photoset']['count_photos']) : 0,
    ];

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Flickr Justified Block: Extracted photoset info: ' . json_encode($photoset_info));
        error_log('Flickr Justified Block: Raw title data: ' . json_encode($data['photoset']['title'] ?? 'MISSING'));
    }

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
 * @param int $per_page Photos per page (max 500)
 * @return array Array with 'photos', 'has_more', 'total', 'page', 'pages', 'album_title'
 */
function flickr_justified_get_photoset_photos_paginated($user_id, $photoset_id, $page = 1, $per_page = 500) {
    // Validate inputs
    if (empty($user_id) || empty($photoset_id) || !is_string($user_id) || !is_string($photoset_id)) {
        return [
            'photos' => [],
            'has_more' => false,
            'total' => 0,
            'page' => 1,
            'pages' => 1
        ];
    }

    // Resolve username to numeric user ID if needed
    $resolved_user_id = flickr_justified_resolve_user_id($user_id);
    if (!$resolved_user_id) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: Failed to resolve user ID for: ' . $user_id);
        }
        return [
            'photos' => [],
            'has_more' => false,
            'total' => 0,
            'page' => 1,
            'pages' => 1
        ];
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Flickr Justified Block: Using resolved user ID: ' . $resolved_user_id . ' (from: ' . $user_id . ')');
    }

    $page = max(1, intval($page));
    $per_page = max(1, min(500, intval($per_page))); // Flickr max is 500

    // Cache key includes page number - use resolved user ID for consistency
    $cache_key = 'flickr_justified_set_page_' . md5($resolved_user_id . '_' . $photoset_id . '_' . $page . '_' . $per_page);

    // Check cache first
    $cached_result = get_transient($cache_key);
    if (!empty($cached_result) && is_array($cached_result) && isset($cached_result['photos'])) {
        return $cached_result;
    }

    // Get API key from settings
    $api_key = '';
    if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_api_key')) {
        $api_key = FlickrJustifiedAdminSettings::get_api_key();
    }

    if (empty($api_key)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: No API key found for photoset: ' . $photoset_id);
        }
        return [
            'photos' => [],
            'has_more' => false,
            'total' => 0,
            'page' => $page,
            'pages' => 1
        ];
    }

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Flickr Justified Block: Making photoset API call for set: ' . $photoset_id . ' user: ' . $resolved_user_id . ' (original: ' . $user_id . ') page: ' . $page);
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: Photoset API request error: ' . $response->get_error_message());
        }
        return [
            'photos' => [],
            'has_more' => false,
            'total' => 0,
            'page' => $page,
            'pages' => 1
        ];
    }

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: Empty response body from photoset API');
        }
        return [
            'photos' => [],
            'has_more' => false,
            'total' => 0,
            'page' => $page,
            'pages' => 1
        ];
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: JSON decode error: ' . json_last_error_msg() . '. Response: ' . $body);
        }
        return [
            'photos' => [],
            'has_more' => false,
            'total' => 0,
            'page' => $page,
            'pages' => 1
        ];
    }

    // Check for API errors first
    if (isset($data['stat']) && $data['stat'] === 'fail') {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $error_msg = isset($data['message']) ? $data['message'] : 'Unknown API error';
            error_log('Flickr Justified Block: API error for photoset ' . $photoset_id . ': ' . $error_msg);
        }
        return [
            'photos' => [],
            'has_more' => false,
            'total' => 0,
            'page' => $page,
            'pages' => 1
        ];
    }

    // Check if photoset data exists and has photos
    if (!isset($data['photoset']) || empty($data['photoset']['photo']) || !is_array($data['photoset']['photo'])) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: No photos found in photoset ' . $photoset_id . '. Response: ' . $body);
        }
        return [
            'photos' => [],
            'has_more' => false,
            'total' => 0,
            'page' => $page,
            'pages' => 1
        ];
    }

    // Extract pagination info
    $total_photos = isset($data['photoset']['total']) ? intval($data['photoset']['total']) : 0;
    $current_page = isset($data['photoset']['page']) ? intval($data['photoset']['page']) : $page;
    $total_pages = isset($data['photoset']['pages']) ? intval($data['photoset']['pages']) : 1;
    $photos_on_page = isset($data['photoset']['photo']) ? count($data['photoset']['photo']) : 0;

    // Get album title using separate API call (only for first page to avoid redundant calls)
    $album_title = '';
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Flickr Justified Block: Checking if should get album title - page: ' . $page);
    }
    if ($page === 1) {
        $photoset_info = flickr_justified_get_photoset_info($user_id, $photoset_id);
        if ($photoset_info && !empty($photoset_info['title'])) {
            $album_title = $photoset_info['title'];
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flickr Justified Block: Retrieved album title: ' . $album_title);
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flickr Justified Block: Failed to retrieve album title for photoset: ' . $photoset_id);
            }
        }
    }

    // Convert photos to individual photo page URLs
    $photo_urls = [];
    foreach ($data['photoset']['photo'] as $photo) {
        if (isset($photo['id']) && !empty($photo['id']) && is_string($photo['id'])) {
            // Sanitize the photo ID (should be numeric)
            $photo_id = preg_replace('/[^0-9]/', '', $photo['id']);
            if (!empty($photo_id)) {
                // Create the standard photo page URL format that our existing functions can handle
                $photo_url = "https://flickr.com/photos/" . urlencode($user_id) . "/" . $photo_id . "/";
                $photo_urls[] = $photo_url;
            }
        }
    }

    $result = [
        'photos' => $photo_urls,
        'has_more' => $current_page < $total_pages,
        'total' => $total_photos,
        'page' => $current_page,
        'pages' => $total_pages,
        'album_title' => $album_title
    ];

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Flickr Justified Block: Retrieved ' . count($photo_urls) . ' photos from set: ' . $photoset_id . ' (page ' . $current_page . ' of ' . $total_pages . ')');
    }

    if (!empty($photo_urls)) {
        // Cache the results (shorter cache for paginated results)
        $cache_duration = HOUR_IN_SECONDS * 6; // 6 hours for individual pages
        if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_cache_duration')) {
            $cache_duration = max(HOUR_IN_SECONDS, FlickrJustifiedAdminSettings::get_cache_duration() / 4); // 1/4 of main cache duration, min 1 hour
        }
        set_transient($cache_key, $result, $cache_duration);
    }

    return $result;
}


/**
 * Render with justified gallery layout
 */
function flickr_justified_render_justified_gallery($url_lines, $block_id, $gap, $image_size, $responsive_settings, $row_height_mode, $row_height, $max_viewport_height, $single_image_alignment, $set_metadata = []) {

    // Get admin breakpoints
    $breakpoints = [];
    if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_breakpoints')) {
        $breakpoints = FlickrJustifiedAdminSettings::get_breakpoints();
    }

    // Get attribution mode (always use builtin PhotoSwipe lightbox)
    $attribution_mode = FlickrJustifiedAdminSettings::get_flickr_attribution_mode();

    // Generate simple structure - JavaScript will organize into responsive rows
    $set_metadata_attr = !empty($set_metadata) ? esc_attr(json_encode($set_metadata)) : '';
    $output = sprintf(
        '<div id="%s" class="flickr-justified-grid" style="--gap: %dpx;" data-responsive-settings="%s" data-breakpoints="%s" data-row-height-mode="%s" data-row-height="%d" data-max-viewport-height="%d" data-single-image-alignment="%s" data-attribution-mode="%s" data-use-builtin-lightbox="%s" data-set-metadata="%s">',
        esc_attr($block_id),
        (int) $gap,
        esc_attr(json_encode($responsive_settings)),
        esc_attr(json_encode($breakpoints)),
        esc_attr($row_height_mode),
        (int) $row_height,
        (int) $max_viewport_height,
        esc_attr($single_image_alignment),
        esc_attr($attribution_mode),
        '1',
        $set_metadata_attr
    );

    foreach ($url_lines as $url) {
        $url = esc_url($url);
        if (empty($url)) continue;

        $is_flickr = (strpos($url, 'flickr.com/photos/') !== false || strpos($url, 'www.flickr.com/photos/') !== false);

        if ($is_flickr) {
            $available_sizes = [
                'original', 'large6k', 'large5k', 'largef', 'large4k', 'large3k',
                'large2048', 'large1600', 'large1024', 'large',
                'medium800', 'medium640', 'medium500', 'medium',
                'small400', 'small320', 'small240',
                'thumbnail100', 'thumbnail150s', 'thumbnail75s'
            ];
            $image_data = flickr_justified_get_flickr_image_sizes_with_dimensions($url, $available_sizes);

            // Debug: Log what we got back from API for this URL
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // Extract photo ID for clearer logging
                $photo_id_match = preg_match('#flickr\.com/photos/[^/]+/(\d+)#', $url, $matches);
                $photo_id_for_log = $photo_id_match ? $matches[1] : 'unknown';

                error_log('Flickr Justified Block: URL processed: ' . $url);
                error_log('Flickr Justified Block: Photo ID extracted: ' . $photo_id_for_log);
                error_log('Flickr Justified Block: Image data count: ' . count($image_data));
                if (empty($image_data)) {
                    error_log('Flickr Justified Block: ❌ FAILED - NO IMAGE DATA for Photo ID: ' . $photo_id_for_log . ' URL: ' . $url);
                } else {
                    error_log('Flickr Justified Block: ✅ SUCCESS - Photo ID: ' . $photo_id_for_log . ' Available sizes: ' . implode(', ', array_keys($image_data)));
                }
            }

            $display_src = isset($image_data[$image_size]['url']) ? $image_data[$image_size]['url'] : '';
            $dimensions = isset($image_data[$image_size]) ? $image_data[$image_size] : null;

            // For PhotoSwipe, select size appropriate for high-res displays (around 2-3x screen width)
            // Target ~3500px for 2560px screens, but allow larger if no intermediate sizes exist
            $best_lightbox_size = flickr_justified_select_best_size($image_data, 3500, 3500);

            // If selection is too small (less than 2x screen width), use original
            if ($best_lightbox_size && isset($image_data[$best_lightbox_size])) {
                $selected_width = $image_data[$best_lightbox_size]['width'];
                if ($selected_width < 3000) {
                    $best_lightbox_size = flickr_justified_select_best_size($image_data, PHP_INT_MAX, PHP_INT_MAX);
                    error_log("PhotoSwipe DEBUG: Selected size too small ({$selected_width}px), using largest: {$best_lightbox_size}");
                } else {
                    error_log("PhotoSwipe DEBUG: Using appropriate size for high-res display: {$best_lightbox_size} ({$selected_width}px)");
                }
            } else {
                $best_lightbox_size = flickr_justified_select_best_size($image_data, PHP_INT_MAX, PHP_INT_MAX);
                error_log("PhotoSwipe DEBUG: Fallback to largest available: {$best_lightbox_size}");
            }

            // Debug: Show ALL raw Flickr API data to see what sizes are actually available
            error_log("PhotoSwipe DEBUG: FULL RAW IMAGE DATA: " . json_encode($image_data, JSON_PRETTY_PRINT));

            // Debug: Show available image sizes with dimensions
            $debug_sizes = [];
            foreach($image_data as $size => $data) {
                $debug_sizes[$size] = $data['width'] . 'x' . $data['height'];
            }
            error_log("PhotoSwipe DEBUG: Available image sizes: " . json_encode($debug_sizes));

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

            // If API failed to get any images from Flickr URLs, handle based on settings
            if (empty($display_src)) {
                $error_mode = FlickrJustifiedAdminSettings::get_privacy_error_mode();

                if ($error_mode === 'show_nothing') {
                    // Skip this photo and continue with the next one
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('Flickr Justified Block: Skipping failed photo (show_nothing mode): ' . $url);
                    }
                    continue;
                } else {
                    // Add an error placeholder for this specific photo
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
            }

            if (!empty($display_src)) {
                $data_attrs = '';
                // Use lightbox image dimensions for data attributes (PhotoSwipe needs these)
                $lightbox_dimensions = null;
                if ($best_lightbox_size && isset($image_data[$best_lightbox_size])) {
                    $lightbox_dimensions = $image_data[$best_lightbox_size];
                    error_log("PhotoSwipe DEBUG: Found lightbox dimensions for size '{$best_lightbox_size}': " . json_encode($lightbox_dimensions));
                } else {
                    error_log("PhotoSwipe DEBUG: No lightbox dimensions found - best_lightbox_size: '{$best_lightbox_size}', image_data keys: " . json_encode(array_keys($image_data)));
                }

                if ($lightbox_dimensions) {
                    $data_attrs = sprintf(' data-width="%d" data-height="%d"', $lightbox_dimensions['width'], $lightbox_dimensions['height']);
                    error_log("PhotoSwipe DEBUG: Setting data attrs: {$data_attrs}");
                } else {
                    error_log("PhotoSwipe DEBUG: No data attrs set - lightbox_dimensions is null");
                }

                // Use PhotoSwipe lightbox settings
                $lightbox_class = 'flickr-builtin-lightbox';
                $gallery_group_attribute = 'data-gallery';
                $gallery_group = esc_attr($block_id);

                // Get attribution settings
                $attribution_mode = FlickrJustifiedAdminSettings::get_flickr_attribution_mode();
                $attribution_text = FlickrJustifiedAdminSettings::get_attribution_text();
                $attribution_position = FlickrJustifiedAdminSettings::get_attribution_position();

                // Build attribution data attributes for lightbox plugins
                $attribution_attrs = '';
                if ($attribution_mode !== 'disabled') {
                    $attribution_attrs = sprintf(' data-flickr-page="%s" data-flickr-attribution-text="%s"',
                        esc_attr($url),
                        esc_attr($attribution_text)
                    );

                    // Add data attributes for common lightbox caption methods
                    $attribution_attrs .= sprintf(' data-caption="%s" data-title="%s" title="%s"',
                        esc_attr($attribution_text),
                        esc_attr($attribution_text),
                        esc_attr($attribution_text)
                    );
                }

                // Build caption overlay if needed
                $caption_overlay = '';
                if ($attribution_mode === 'caption_overlay') {
                    $position_classes = [
                        'bottom_left' => 'bottom: 8px; left: 8px;',
                        'bottom_right' => 'bottom: 8px; right: 8px;',
                        'bottom_center' => 'bottom: 8px; left: 50%; transform: translateX(-50%);',
                        'top_left' => 'top: 8px; left: 8px;',
                        'top_right' => 'top: 8px; right: 8px;'
                    ];

                    $position_style = isset($position_classes[$attribution_position])
                        ? $position_classes[$attribution_position]
                        : $position_classes['bottom_right'];

                    $caption_overlay = sprintf(
                        '<div class="flickr-attribution-overlay" style="
                            position: absolute;
                            %s
                            background: rgba(0,0,0,0.7);
                            color: white;
                            padding: 4px 8px;
                            border-radius: 3px;
                            font-size: 12px;
                            z-index: 10;
                            pointer-events: none;
                        ">
                            <a href="%s" target="_blank" rel="noopener" style="color: white; text-decoration: none; pointer-events: auto;">%s</a>
                        </div>',
                        $position_style,
                        esc_attr($url),
                        esc_html($attribution_text)
                    );
                }

                $output .= sprintf(
                    '<article class="flickr-card" style="position: relative;">
                        <a href="%s" class="%s" %s="%s" %s%s>
                            <img src="%s" loading="lazy" decoding="async" alt="">
                        </a>%s
                    </article>',
                    esc_url($lightbox_src),
                    esc_attr($lightbox_class),
                    esc_attr($gallery_group_attribute),
                    esc_attr($gallery_group),
                    $data_attrs,
                    $attribution_attrs,
                    esc_url($display_src),
                    $caption_overlay
                );
            }
        } else {
            // Direct image URL - use PhotoSwipe lightbox
            $lightbox_class = 'flickr-builtin-lightbox';
            $gallery_group_attribute = 'data-gallery';
            $gallery_group = esc_attr($block_id);
            $output .= sprintf(
                '<article class="flickr-card">
                    <a href="%s" class="%s" %s="%s">
                        <img src="%s" loading="lazy" decoding="async" alt="">
                    </a>
                </article>',
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
    $default_responsive = [];
    if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_configured_default_responsive_settings')) {
        $default_responsive = FlickrJustifiedAdminSettings::get_configured_default_responsive_settings();
    } else {
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

    // Process any Flickr sets/albums and expand them to individual photo URLs
    $expanded_urls = [];
    $set_metadata = []; // Store metadata for lazy loading
    foreach ($url_lines as $url) {
        $set_info = flickr_justified_parse_set_url($url);
        if ($set_info) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flickr Justified Block: Parsing set URL: ' . $url . ' -> user_id: ' . $set_info['user_id'] . ', photoset_id: ' . $set_info['photoset_id']);
            }
            // This is a Flickr set/album URL - get first page of photos
            $set_result = flickr_justified_get_photoset_photos_paginated($set_info['user_id'], $set_info['photoset_id'], 1, 500);
            if (!empty($set_result['photos'])) {
                $expanded_urls = array_merge($expanded_urls, $set_result['photos']);

                // Always store metadata for sets, even single-page ones (for consistency)
                $set_metadata[] = [
                    'user_id' => $set_info['user_id'],
                    'photoset_id' => $set_info['photoset_id'],
                    'current_page' => 1,
                    'total_pages' => isset($set_result['pages']) ? $set_result['pages'] : 1,
                    'total_photos' => isset($set_result['total']) ? $set_result['total'] : 0,
                    'loaded_photos' => count($set_result['photos']),
                    'has_more' => isset($set_result['has_more']) ? $set_result['has_more'] : false
                ];

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Flickr Justified Block: Expanded set ' . $set_info['photoset_id'] . ' to ' . count($set_result['photos']) . ' photos (page 1 of ' . $set_result['pages'] . ')');
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Flickr Justified Block: Failed to expand set: ' . $url . ' - Result: ' . json_encode($set_result));
                }
                // Keep the original URL if set expansion failed
                $expanded_urls[] = $url;
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flickr Justified Block: URL not recognized as set/album: ' . $url);
            }
            // Regular photo URL or direct image URL
            $expanded_urls[] = $url;
        }
    }

    $url_lines = array_filter($expanded_urls);

    if (empty($url_lines)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: No URLs to process after expansion. Final URLs: ' . json_encode($url_lines));
        }
        return '';
    }

    // Generate unique ID for this block instance
    $block_id = 'flickr-justified-' . uniqid();

    // Use justified gallery layout
    return flickr_justified_render_justified_gallery(
        $url_lines, $block_id, $gap, $image_size, $responsive_settings, $row_height_mode, $row_height, $max_viewport_height, $single_image_alignment, $set_metadata
    );
}
