<?php
/**
 * Consolidated cache management for Flickr Justified Block
 *
 * Handles all WordPress transient caching for:
 * - Flickr API responses (photo info, sizes, albums)
 * - User ID resolution
 * - Photo statistics
 *
 * @package FlickrJustifiedBlock
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Centralized cache manager for Flickr data
 */
class FlickrJustifiedCache {

    /**
     * Cache key prefix to namespace all our transients
     */
    private const PREFIX = 'flickr_justified_';

    /**
     * Cached duration value (loaded once)
     */
    private static $cache_duration = null;

    /**
     * In-memory cache for current request to avoid repeated transient lookups
     */
    private static $request_cache = [];

    /**
     * Get configured cache duration in seconds
     */
    private static function get_duration() {
        if (null === self::$cache_duration) {
            $hours = 168; // Default 7 days
            if (class_exists('FlickrJustifiedAdminSettings')) {
                $configured = FlickrJustifiedAdminSettings::get_cache_duration();
                if ($configured > 0) {
                    self::$cache_duration = $configured;
                    return $configured;
                }
            }
            self::$cache_duration = $hours * HOUR_IN_SECONDS;
        }
        return self::$cache_duration;
    }

    /**
     * Build a cache key with our prefix
     */
    private static function key($parts) {
        if (is_array($parts)) {
            $parts = implode('_', $parts);
        }
        return self::PREFIX . $parts;
    }

    /**
     * Get from cache (with request-level caching)
     */
    public static function get($key) {
        $full_key = self::key($key);

        // Check request cache first
        if (isset(self::$request_cache[$full_key])) {
            return self::$request_cache[$full_key];
        }

        $value = get_transient($full_key);

        // Store in request cache
        if (false !== $value) {
            self::$request_cache[$full_key] = $value;
        }

        return $value;
    }

    /**
     * Set to cache with configured duration
     */
    public static function set($key, $value, $expiration = null) {
        if (null === $expiration) {
            $expiration = self::get_duration();
        }

        $full_key = self::key($key);

        // Store in request cache
        self::$request_cache[$full_key] = $value;

        return set_transient($full_key, $value, $expiration);
    }

    /**
     * Delete from cache
     */
    public static function delete($key) {
        $full_key = self::key($key);

        // Remove from request cache
        unset(self::$request_cache[$full_key]);

        return delete_transient($full_key);
    }

    /**
     * Clear all Flickr Justified cache entries and stored data
     * Uses direct database queries for efficiency
     *
     * This clears:
     * - All transients (photo data, album data, API responses)
     * - Cache warmer queue
     * - Known URLs registry
     * - Rate limiting counters
     * - In-memory request cache
     */
    public static function clear_all() {
        global $wpdb;

        // Clear in-memory request cache
        self::$request_cache = [];

        // Clear all transients with our prefix
        $patterns = [
            '_transient_' . self::PREFIX . '%',
            '_transient_timeout_' . self::PREFIX . '%',
            '_site_transient_' . self::PREFIX . '%',
            '_site_transient_timeout_' . self::PREFIX . '%',
        ];

        // Delete from options table
        foreach ($patterns as $pattern) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $pattern
                )
            );
        }

        // Delete from sitemeta if multisite
        if (is_multisite() && property_exists($wpdb, 'sitemeta')) {
            foreach (['_site_transient_' . self::PREFIX . '%', '_site_transient_timeout_' . self::PREFIX . '%'] as $pattern) {
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
                        $pattern
                    )
                );
            }
        }

        // Clear cache warmer data
        delete_option('flickr_justified_cache_warmer_queue');
        delete_option('flickr_justified_known_flickr_urls');

        // Clear rate limiting transients (for REST API lazy loading)
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_flickr_lazy_load_%'
             OR option_name LIKE '_transient_timeout_flickr_lazy_load_%'"
        );

        return true;
    }

    // ========================================================================
    // FLICKR API CACHE METHODS
    // ========================================================================

    /**
     * Get or fetch Flickr photo info (rotation, stats, dates, etc.)
     * This is the ONLY method that should call flickr.photos.getInfo
     */
    public static function get_photo_info($photo_id, $force_refresh = false) {
        $photo_id = trim((string) $photo_id);
        if ('' === $photo_id) {
            return [];
        }

        $cache_key = ['photo_info', $photo_id];

        if (!$force_refresh) {
            $cached = self::get($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        // Fetch from API
        $api_key = self::get_api_key();
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

        // Cache for configured duration
        self::set($cache_key, $photo_info);

        return $photo_info;
    }

    /**
     * Get photo stats (views, comments, favorites) from cached photo info
     * OPTIMIZED: Uses cached photo_info, extracts and caches stats separately
     */
    public static function get_photo_stats($photo_id) {
        $photo_id = trim((string) $photo_id);
        if ('' === $photo_id) {
            return [];
        }

        // Check if we have stats cached separately
        $stats_cache_key = ['photo_stats', $photo_id];
        $cached_stats = self::get($stats_cache_key);
        if (is_array($cached_stats) && !empty($cached_stats)) {
            return $cached_stats;
        }

        // Get photo info (will use cache if available)
        $photo_info = self::get_photo_info($photo_id);

        if (empty($photo_info)) {
            return [];
        }

        // Extract stats
        $views = isset($photo_info['views']) ? max(0, (int) $photo_info['views']) : 0;

        $comments = 0;
        if (isset($photo_info['comments'])) {
            if (is_array($photo_info['comments']) && isset($photo_info['comments']['_content'])) {
                $comments = (int) $photo_info['comments']['_content'];
            } elseif (is_scalar($photo_info['comments'])) {
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

        $stats = [
            'views' => $views,
            'comments' => max(0, $comments),
            'favorites' => max(0, $favorites),
            'date' => $date,
        ];

        // Cache stats separately for faster lookups
        self::set($stats_cache_key, $stats);

        return $stats;
    }

    /**
     * Get photo dimensions and URLs for all requested sizes
     * OPTIMIZED: Only fetches photo_info once if needed
     */
    public static function get_photo_sizes($photo_id, $page_url, $requested_sizes = ['large', 'original'], $needs_metadata = false) {
        $photo_id = trim((string) $photo_id);
        if ('' === $photo_id) {
            return [];
        }

        // Build cache key
        $requested_sizes_key = md5(implode(',', $requested_sizes));
        $cache_suffix = ['dims', $photo_id, $requested_sizes_key, (int) $needs_metadata];

        // Try base cache key first
        $base_cache_key = $cache_suffix;
        $cached_result = self::get($base_cache_key);
        if (is_array($cached_result) && !empty($cached_result)) {
            return $cached_result;
        }

        // Get photo info ONCE (will use cache if available)
        // We need this for lastupdate timestamp for versioning
        $photo_info = [];
        if ($needs_metadata) {
            // If metadata needed, fetch photo_info now and reuse it
            $photo_info = self::get_photo_info($photo_id);
        } else {
            // Just check if we have it cached (don't fetch if not needed)
            $info_cache_key = ['photo_info', $photo_id];
            $photo_info = self::get($info_cache_key);
            if (false === $photo_info) {
                $photo_info = [];
            }
        }

        $lastupdate = '';
        if (isset($photo_info['dates']['lastupdate'])) {
            $lastupdate = (string) $photo_info['dates']['lastupdate'];
        }
        if ('' === $lastupdate) {
            $lastupdate = 'na';
        }

        // Try versioned cache key
        $versioned_cache_key = array_merge($cache_suffix, [$lastupdate]);
        $versioned_cached_result = self::get($versioned_cache_key);
        if (is_array($versioned_cached_result) && !empty($versioned_cached_result)) {
            self::set($base_cache_key, $versioned_cached_result);
            return $versioned_cached_result;
        }

        // Fetch from API
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return [];
        }

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

        // Map API sizes to requested sizes with dimensions
        $result = self::map_sizes_with_dimensions($data['sizes']['size'], $requested_sizes);

        if (!empty($result)) {
            // Add metadata if requested (reuse photo_info we already fetched)
            if ($needs_metadata) {
                if (empty($photo_info)) {
                    // Should not happen, but fallback just in case
                    $photo_info = self::get_photo_info($photo_id);
                }

                if (!empty($photo_info)) {
                    $result['_photo_info'] = $photo_info;

                    // Get stats from same photo_info (no extra API call)
                    $stats = self::get_photo_stats($photo_id);
                    if (!empty($stats)) {
                        $result['_stats'] = $stats;
                    }

                    if (isset($photo_info['rotation'])) {
                        $result['_rotation'] = self::normalize_rotation($photo_info['rotation']);
                    }
                }
            }

            if (!isset($result['_lastupdate'])) {
                $result['_lastupdate'] = $lastupdate;
            }

            // Cache both versioned and base keys
            self::set($versioned_cache_key, $result);
            self::set($base_cache_key, $result);
        }

        return $result;
    }

    /**
     * Map Flickr API size responses to requested size keys with dimensions
     */
    private static function map_sizes_with_dimensions($api_sizes, $requested_sizes) {
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

        $result = [];

        foreach ($requested_sizes as $requested_size) {
            $preferred_labels = $size_mapping[$requested_size] ?? [$requested_size];

            foreach ($preferred_labels as $label) {
                foreach ($api_sizes as $size_info) {
                    if (isset($size_info['label'], $size_info['source'], $size_info['width'], $size_info['height']) &&
                        $size_info['label'] === $label && !empty($size_info['source'])) {
                        $result[$requested_size] = [
                            'url' => esc_url_raw($size_info['source']),
                            'width' => (int) $size_info['width'],
                            'height' => (int) $size_info['height']
                        ];
                        break 2;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Resolve Flickr username to numeric user ID
     */
    public static function resolve_user_id($username) {
        if (empty($username) || !is_string($username)) {
            return false;
        }

        // If it's already numeric, return as-is
        if (is_numeric($username)) {
            return $username;
        }

        // Check cache
        $cache_key = ['user_id', md5($username)];
        $cached = self::get($cache_key);
        if (!empty($cached)) {
            return $cached;
        }

        // Fetch from API
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return false;
        }

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
        self::set($cache_key, $user_id, DAY_IN_SECONDS);

        return $user_id;
    }

    /**
     * Get photoset/album info (title, description, count)
     */
    public static function get_photoset_info($user_id, $photoset_id) {
        if (empty($user_id) || empty($photoset_id)) {
            return false;
        }

        // Resolve username to numeric ID
        $resolved_user_id = self::resolve_user_id($user_id);
        if (!$resolved_user_id) {
            return false;
        }

        // Check cache
        $cache_key = ['set_info', md5($resolved_user_id . '_' . $photoset_id)];
        $cached = self::get($cache_key);
        if (!empty($cached) && is_array($cached)) {
            return $cached;
        }

        // Fetch from API
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return false;
        }

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

        self::set($cache_key, $photoset_info, 6 * HOUR_IN_SECONDS);

        return $photoset_info;
    }

    /**
     * Get paginated photos from a photoset/album
     * OPTIMIZED: Extracts title directly from response, no extra API call
     */
    public static function get_photoset_photos($user_id, $photoset_id, $page = 1, $per_page = 50) {
        $page = max(1, (int) $page);
        $per_page = max(1, min(500, (int) $per_page));

        if (empty($user_id) || empty($photoset_id) || !is_string($user_id) || !is_string($photoset_id)) {
            return self::empty_photoset_result($page);
        }

        // Resolve username
        $resolved_user_id = self::resolve_user_id($user_id);
        if (!$resolved_user_id) {
            return self::empty_photoset_result($page);
        }

        // Check cache
        $cache_key = ['set_page_v2', md5($resolved_user_id . '_' . $photoset_id . '_' . $page . '_' . $per_page)];
        $cached = self::get($cache_key);
        if (!empty($cached) && is_array($cached) && isset($cached['photos'])) {
            return $cached;
        }

        // Fetch from API
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return self::empty_photoset_result($page);
        }

        $api_url = add_query_arg([
            'method' => 'flickr.photosets.getPhotos',
            'api_key' => $api_key,
            'photoset_id' => $photoset_id,
            'user_id' => $resolved_user_id,
            'per_page' => $per_page,
            'page' => $page,
            'extras' => 'url_m,url_l,url_o',
            'format' => 'json',
            'nojsoncallback' => 1,
        ], 'https://api.flickr.com/services/rest/');

        $response = wp_remote_get($api_url, [
            'timeout' => 15,
            'user-agent' => 'WordPress Flickr Justified Block'
        ]);

        if (is_wp_error($response)) {
            return self::empty_photoset_result($page);
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);
        if ($response_code < 200 || $response_code >= 300) {
            return self::empty_photoset_result($page);
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return self::empty_photoset_result($page);
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::empty_photoset_result($page);
        }

        if (isset($data['stat']) && 'fail' === $data['stat']) {
            return self::empty_photoset_result($page);
        }

        if (!isset($data['photoset']) || empty($data['photoset']['photo']) || !is_array($data['photoset']['photo'])) {
            return self::empty_photoset_result($page);
        }

        // Extract pagination info
        $total_photos = isset($data['photoset']['total']) ? (int) $data['photoset']['total'] : 0;
        $current_page = isset($data['photoset']['page']) ? (int) $data['photoset']['page'] : $page;
        $total_pages = isset($data['photoset']['pages']) ? (int) $data['photoset']['pages'] : 1;

        // OPTIMIZED: Get album title directly from getPhotos response (no extra API call!)
        $album_title = '';
        if (isset($data['photoset']['title'])) {
            if (is_string($data['photoset']['title'])) {
                $album_title = sanitize_text_field($data['photoset']['title']);
            } elseif (is_array($data['photoset']['title']) && isset($data['photoset']['title']['_content'])) {
                $album_title = sanitize_text_field($data['photoset']['title']['_content']);
            }
        }

        // If we got the title from this response, cache it in set_info to avoid future getInfo calls
        if (!empty($album_title) && 1 === $page) {
            $set_info_cache_key = ['set_info', md5($resolved_user_id . '_' . $photoset_id)];
            $existing_info = self::get($set_info_cache_key);
            if (false === $existing_info || empty($existing_info)) {
                // Cache just the title info to prevent getInfo API call
                self::set($set_info_cache_key, [
                    'title' => $album_title,
                    'description' => '',
                    'photo_count' => $total_photos,
                ], 6 * HOUR_IN_SECONDS);
            }
        }

        // Convert to photo URLs
        $photo_urls = [];
        foreach ($data['photoset']['photo'] as $photo) {
            if (empty($photo['id']) || !is_string($photo['id'])) {
                continue;
            }

            $photo_id = preg_replace('/[^0-9]/', '', $photo['id']);
            if (empty($photo_id)) {
                continue;
            }

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
            $cache_duration = 6 * HOUR_IN_SECONDS;
            $configured_duration = self::get_duration();
            if ($configured_duration > 0) {
                $cache_duration = max(HOUR_IN_SECONDS, (int) floor($configured_duration / 4));
            }
            self::set($cache_key, $result, $cache_duration);
        }

        return $result;
    }

    /**
     * Empty photoset result helper
     */
    private static function empty_photoset_result($page = 1) {
        return [
            'photos' => [],
            'has_more' => false,
            'total' => 0,
            'page' => max(1, (int) $page),
            'pages' => 1,
            'album_title' => '',
        ];
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    /**
     * Get Flickr API key from settings
     */
    private static function get_api_key() {
        static $api_key = null;

        if (null !== $api_key) {
            return $api_key;
        }

        if (class_exists('FlickrJustifiedAdminSettings')) {
            $api_key = FlickrJustifiedAdminSettings::get_api_key();
            return is_string($api_key) ? trim($api_key) : '';
        }

        $api_key = '';
        return $api_key;
    }

    /**
     * Normalize rotation to 0-359 degrees
     */
    private static function normalize_rotation($rotation) {
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
}
