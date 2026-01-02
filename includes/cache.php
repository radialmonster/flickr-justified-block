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
     * Track API calls made during this request for rate limit monitoring
     */
    private static $api_calls_this_request = 0;

    /**
     * Cache key for API call counter (per site on multisite)
     */
    private static function get_api_counter_key() {
        $suffix = '';
        if (is_multisite()) {
            $suffix = '_' . get_current_blog_id();
        }
        return 'flickr_justified_api_call_count' . $suffix;
    }

    /**
     * SINGLE SOURCE OF TRUTH: Centralized Flickr size definitions
     * All size mappings throughout the plugin derive from this ONE definition
     *
     * Structure:
     * - 'suffix': Flickr URL suffix (sq, t, s, etc.) for album responses
     * - 'labels': Array of Flickr API label names (in fallback priority order)
     *
     * @return array Map of our size names to their Flickr properties
     */
    private static function get_size_definitions() {
        static $definitions = null;

        if (null === $definitions) {
            $definitions = [
                'original'      => ['suffix' => 'o', 'labels' => ['Original']],
                'large6k'       => ['suffix' => null, 'labels' => ['Large 6144', 'Original']],
                'large5k'       => ['suffix' => null, 'labels' => ['Large 5120', 'Large 6144', 'Original']],
                'largef'        => ['suffix' => null, 'labels' => ['Large 4096', 'Large 5120', 'Original']],
                'large4k'       => ['suffix' => null, 'labels' => ['Large 4096', 'Large 5120', 'Original']],
                'large3k'       => ['suffix' => null, 'labels' => ['Large 3072', 'Large 4096', 'Original']],
                'large2048'     => ['suffix' => 'k', 'labels' => ['Large 2048', 'Large 3072', 'Original']],
                'large1600'     => ['suffix' => 'h', 'labels' => ['Large 1600', 'Large 2048', 'Original']],
                'large1024'     => ['suffix' => 'l', 'labels' => ['Large 1024', 'Large 1600', 'Original']],
                'large'         => ['suffix' => null, 'labels' => ['Large', 'Large 1024', 'Original']],
                'medium800'     => ['suffix' => 'c', 'labels' => ['Medium 800', 'Large', 'Original']],
                'medium640'     => ['suffix' => 'z', 'labels' => ['Medium 640', 'Medium 800', 'Large']],
                'medium500'     => ['suffix' => 'm', 'labels' => ['Medium', 'Medium 640', 'Large']],
                'medium'        => ['suffix' => null, 'labels' => ['Medium', 'Medium 640', 'Large']],
                'small400'      => ['suffix' => null, 'labels' => ['Small 400', 'Medium']],
                'small320'      => ['suffix' => 'n', 'labels' => ['Small 320', 'Small 400', 'Medium']],
                'small240'      => ['suffix' => 's', 'labels' => ['Small', 'Small 320', 'Medium']],
                'thumbnail100'  => ['suffix' => 't', 'labels' => ['Thumbnail']],
                'thumbnail150s' => ['suffix' => 'q', 'labels' => ['Large Square 150', 'Large Square', 'Square']],
                'thumbnail75s'  => ['suffix' => 'sq', 'labels' => ['Square 75', 'Square']],
            ];
        }

        return $definitions;
    }

    /**
     * Get suffix-to-name mapping (for album responses)
     * Derived from get_size_definitions()
     */
    private static function get_size_suffix_map() {
        static $suffix_map = null;

        if (null === $suffix_map) {
            $suffix_map = [];
            foreach (self::get_size_definitions() as $size_name => $props) {
                if (!empty($props['suffix'])) {
                    $suffix_map[$props['suffix']] = $size_name;
                }
            }
        }

        return $suffix_map;
    }

    /**
     * Get label-to-name mapping (for individual photo API calls)
     * Derived from get_size_definitions()
     */
    private static function get_size_label_map() {
        static $label_map = null;

        if (null === $label_map) {
            $label_map = [];
            foreach (self::get_size_definitions() as $size_name => $props) {
                if (!empty($props['labels'])) {
                    $label_map[$size_name] = $props['labels'];
                }
            }
        }

        return $label_map;
    }

    /**
     * Get list of all size names available from album responses
     * Derived from get_size_definitions()
     */
    private static function get_comprehensive_size_list() {
        static $size_list = null;

        if (null === $size_list) {
            $suffix_map = self::get_size_suffix_map();
            $size_list = array_values(array_unique(array_values($suffix_map)));
        }

        return $size_list;
    }

    /**
     * Get configured cache duration in seconds
     */
    private static function get_duration() {
        if (null === self::$cache_duration) {
            $hours = 168; // Default 7 days
            if (class_exists('FlickrJustifiedAdminSettings')) {
                $configured = FlickrJustifiedAdminSettings::get_cache_duration();
                if ($configured > 0) {
                    // Cap duration to prevent effectively infinite caches
                    $configured = max(HOUR_IN_SECONDS, (int) $configured);
                    $configured = min(90 * DAY_IN_SECONDS, $configured); // 90 days max
                    self::$cache_duration = $configured;
                    return $configured;
                }
            }
            self::$cache_duration = $hours * HOUR_IN_SECONDS;
        }
        return self::$cache_duration;
    }

    /**
     * Build a cache key with our prefix and version
     */
    private static function key($parts) {
        if (is_array($parts)) {
            $parts = implode('_', $parts);
        }
        return self::PREFIX . $parts . '_v' . self::get_version();
    }

    /**
     * Get the current cache version
     */
    private static function get_version() {
        static $version = null;

        if (null === $version) {
            $version = get_option('flickr_justified_cache_version');
            if (!$version) {
                $version = 1;
                add_option('flickr_justified_cache_version', $version);
            }
        }
        return $version;
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

        // Increment cache version to invalidate object cache entries without flushing entire site
        $current_version = (int) get_option('flickr_justified_cache_version', 1);
        update_option('flickr_justified_cache_version', $current_version + 1);
        // Reset API call counter
        delete_transient(self::get_api_counter_key());

        // Clear all transients with our prefix
        $patterns = [
            '_transient_' . self::PREFIX . "%",
            '_transient_timeout_' . self::PREFIX . "%",
            '_site_transient_' . self::PREFIX . "%",
            '_site_transient_timeout_' . self::PREFIX . "%",
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

        // Automatically rebuild known URLs and queue from posts after clearing
        if (class_exists('FlickrJustifiedCacheWarmer')) {
            $map = FlickrJustifiedCacheWarmer::rebuild_known_urls();

            // Prime the queue from the rebuilt known URLs
            $queue_method = new ReflectionMethod('FlickrJustifiedCacheWarmer', 'prime_queue_from_known_urls');
            $queue_method->setAccessible(true);
            $queue = $queue_method->invoke(null, true);

            // Reschedule the cron with correct interval (in case it was using old schedule)
            $clear_method = new ReflectionMethod('FlickrJustifiedCacheWarmer', 'clear_scheduled_events');
            $clear_method->setAccessible(true);
            $clear_method->invoke(null);

            // Schedule with new interval
            $schedule_method = new ReflectionMethod('FlickrJustifiedCacheWarmer', 'maybe_schedule_recurring_event');
            $schedule_method->setAccessible(true);
            $schedule_method->invoke(null);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                $url_count = 0;
                foreach ($map as $urls) {
                    $url_count += count($urls);
                }
                error_log(sprintf(
                    'Flickr cache cleared - rebuilt %d posts with %d URLs, queue has %d items, rescheduled cron',
                    count($map),
                    $url_count,
                    count($queue)
                ));
            }
        }

        // Clear rate limiting transients (for REST API lazy loading)
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_flickr_lazy_load_%' 
             OR option_name LIKE '_transient_timeout_flickr_lazy_load_%'"
        );

        return true;
    }

    /**
     * Increment API call counter (persistent across requests)
     */
    public static function increment_api_calls() {
        self::$api_calls_this_request++;

        $counter_key = self::get_api_counter_key();

        // Try to use object cache increment when available
        $new_count = null;
        if (function_exists('wp_cache_incr')) {
            $new_count = wp_cache_incr($counter_key, 1, '', 0);
            if (false === $new_count) {
                wp_cache_add($counter_key, 0, '', HOUR_IN_SECONDS);
                $new_count = wp_cache_incr($counter_key, 1, '', 0);
            }
        }

        // Fallback to transient counter
        if (null === $new_count || false === $new_count) {
            $count = (int) get_transient($counter_key) ?: 0;
            set_transient($counter_key, $count + 1, HOUR_IN_SECONDS);
        }
    }

    /**
     * Get total API calls made (persistent counter)
     */
    public static function get_api_call_count() {
        $counter_key = self::get_api_counter_key();
        $count = 0;

        if (function_exists('wp_cache_get')) {
            $cached = wp_cache_get($counter_key);
            if (false !== $cached && is_numeric($cached)) {
                $count = (int) $cached;
            }
        }

        if (0 === $count) {
            $count = (int) get_transient($counter_key) ?: 0;
        }

        return $count;
    }

    /**
     * Check if we can make another Flickr API call without exceeding quota
     * Flickr limit: 3600 calls per hour
     * We use 3550 as the cutoff to leave a safety buffer
     *
     * @return bool True if we can make the call, false if quota exceeded
     */
    public static function can_make_api_call() {
        $current_count = self::get_api_call_count();
        $max_calls = (int) apply_filters('flickr_justified_api_hourly_cap', 3550); // Conservative limit (50 call buffer)
        if ($max_calls < 100) {
            $max_calls = 100;
        } elseif ($max_calls > 3600) {
            $max_calls = 3600;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $caller = isset($backtrace[1]) ? $backtrace[1]['function'] : 'unknown';
            error_log(sprintf(
                'Flickr API quota check: caller=%s count=%d/%d will_return=%s',
                $caller,
                $current_count,
                $max_calls,
                $current_count >= $max_calls ? 'FALSE' : 'TRUE'
            ));
        }

        if ($current_count >= $max_calls) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'Flickr API quota check: %d/%d calls used this hour - blocking new calls',
                    $current_count,
                    $max_calls
                ));
            }
            return false;
        }

        return true;
    }

    /**
     * Check if response indicates rate limiting
     * Returns true if rate limited, false otherwise
     */
    public static function is_rate_limited_response($response, $data = null, $context = null) {
        // Check HTTP status code
        if (!is_wp_error($response)) {
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code === 429) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $log_msg = 'Flickr rate limit detected: HTTP 429';
                    if ($context) {
                        $log_msg .= ' | ' . $context;
                    }
                    error_log($log_msg);
                }
                return true;
            }
        }

        // Check Flickr API error code
        if (is_array($data) && isset($data['stat']) && $data['stat'] === 'fail') {
            if (isset($data['code'])) {
                // Flickr error code 17 = User not found (not rate limit)
                // Flickr doesn't have a specific rate limit error code, but typically returns generic errors
                // We'll rely on 429 HTTP status primarily
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $log_msg = 'Flickr API error response: ' . print_r($data, true);
                    if ($context) {
                        $log_msg .= "\n" . $context;
                    }
                    error_log($log_msg);
                }
            }
        }

        return false;
    }

    // ========================================================================
    // CACHE WARMING METHODS
    // ========================================================================

    /**
     * Warm a batch of URLs for manual cache warming (AJAX).
     *
     * @param array $urls Array of URLs to warm.
     * @return array Result with processed, failed, rate_limited, and api_calls counts.
     */
    public static function warm_batch($urls) {
        if (!is_array($urls)) {
            return [
                'processed' => 0,
                'failed' => 0,
                'total' => 0,
                'rate_limited' => false,
                'api_calls' => 0
            ];
        }

        // Track API call count
        $api_call_count_before = self::get_api_call_count();

        $processed = 0;
        $failed = 0;
        $rate_limited = false;
        $errors = [];

        foreach ($urls as $url) {
            try {
                // For manual warming, we process one page at a time to avoid timeouts
                // The background cron warmer handles pagination automatically
                $result = FlickrJustifiedCacheWarmer::warm_url($url, 1);

                if ($result === 'rate_limited') {
                    $rate_limited = true;
                    break; // Stop processing this batch
                } elseif (is_array($result) && isset($result['success'])) {
                    // Album result with pagination info
                    if ($result['success']) {
                        $processed++;
                    } else {
                        $failed++;
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            $errors[] = 'Failed to warm album: ' . $url;
                        }
                    }
                } elseif ($result) {
                    // Simple success (photo URL)
                    $processed++;
                } else {
                    $failed++;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        $errors[] = 'Failed to warm: ' . $url;
                    }
                }
            } catch (Exception $e) {
                $failed++;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $errors[] = 'Exception for ' . $url . ': ' . $e->getMessage();
                    error_log('Flickr warm_batch exception: ' . $e->getMessage() . ' for URL: ' . $url);
                }
            }
        }

        $api_call_count_after = self::get_api_call_count();
        $api_calls_made = $api_call_count_after - $api_call_count_before;

        $result = [
            'processed' => $processed,
            'failed' => $failed,
            'total' => count($urls),
            'rate_limited' => $rate_limited,
            'api_calls' => $api_calls_made
        ];

        if (!empty($errors) && defined('WP_DEBUG') && WP_DEBUG) {
            $result['errors'] = $errors;
        }

        // Add diagnostic info when rate limited happens very quickly
        if ($rate_limited && $api_calls_made <= 2) {
            $result['diagnostic'] = 'Rate limit hit after only ' . $api_calls_made . ' API call(s). This may indicate you hit the limit from previous attempts, or Flickr is returning HTTP 429.';
        }

        return $result;
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
                // Check if this is a negative cache entry (photo not found/private)
                if (isset($cached['not_found']) && $cached['not_found']) {
                    return []; // Return empty array, don't retry API call
                }
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

        // Check API quota before making call
        if (!self::can_make_api_call()) {
            return ['rate_limited' => true];
        }

        $max_retries = 2;
        $retry_delay = 2; // seconds
        $response = null;

        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            $response = wp_remote_get($api_url, [
                'timeout' => 30,
                'user-agent' => 'WordPress Flickr Justified Block'
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                break; // Success, exit loop
            }

            if ($attempt < $max_retries) {
                sleep($retry_delay);
            }
        }

        // Track API call
        self::increment_api_calls();

        if (is_wp_error($response)) {
            // Cache negative result to prevent repeated failed API calls
            self::set($cache_key, ['not_found' => true]);
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Build context string for error logging - use direct photo URL
        $context = 'Photo ID: ' . $photo_id . ' | Direct URL: https://www.flickr.com/photo.gne?id=' . $photo_id;

        // Add current post/page context if available
        $post_id = get_the_ID();
        // Fall back to global set during AJAX requests
        if (!$post_id && isset($GLOBALS['flickr_justified_current_post_id'])) {
            $post_id = $GLOBALS['flickr_justified_current_post_id'];
        }
        if ($post_id) {
            $context .= ' | Post/Page ID: ' . $post_id . ' | URL: ' . get_permalink($post_id);
        }

        // Check for rate limiting
        if (self::is_rate_limited_response($response, $data, $context)) {
            return ['rate_limited' => true];
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);

        // Photo not found, deleted, or private (404, 403, etc)
        if ($response_code < 200 || $response_code >= 300) {
            // Cache negative result to prevent repeated failed API calls
            self::set($cache_key, ['not_found' => true]);
            return [];
        }

        // Check for Flickr API error (photo deleted, private, permission denied)
        if (isset($data['stat']) && $data['stat'] === 'fail') {
            // Cache negative result
            self::set($cache_key, ['not_found' => true, 'error' => $data]);
            return [];
        }

        if (empty($data['photo']) || !is_array($data['photo'])) {
            // Cache negative result
            self::set($cache_key, ['not_found' => true]);
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
            // Check for negative cache (deleted/private photo)
            if (isset($cached_stats['not_found']) && $cached_stats['not_found']) {
                return []; // Skip deleted photos immediately
            }
            return $cached_stats;
        }

        // Get photo info (will use cache if available)
        $photo_info = self::get_photo_info($photo_id);

        // Check for rate limiting
        if (isset($photo_info['rate_limited']) && $photo_info['rate_limited']) {
            return ['rate_limited' => true];
        }

        if (empty($photo_info)) {
            // Cache empty stats for deleted/private photos to skip them faster next time
            self::set($stats_cache_key, ['not_found' => true]);
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
    public static function get_photo_sizes($photo_id, $page_url, $requested_sizes = ['large', 'original'], $needs_metadata = false, $force_refresh = false) {
        $photo_id = trim((string) $photo_id);
        if ('' === $photo_id) {
            return [];
        }

        // OPTIMIZATION: First check for comprehensive album-cached data
        // Album responses cache ALL sizes + stats in one shot to avoid per-photo API calls
        if (!$force_refresh) {
            $comprehensive_sizes = self::get_comprehensive_size_list();
            $comprehensive_key_hash = md5(implode(',', $comprehensive_sizes));
            $comprehensive_cache_key = ['dims', $photo_id, $comprehensive_key_hash];

            $comprehensive_cached = self::get($comprehensive_cache_key);
            if (is_array($comprehensive_cached) && !empty($comprehensive_cached)) {
                // We have comprehensive data from album response! Extract what we need.
                if (!isset($comprehensive_cached['not_found'])) {
                    // Filter to only return requested sizes (plus metadata if present)
                    $filtered_result = [];
                    $missing_sizes = [];

                    foreach ($requested_sizes as $size) {
                        if (isset($comprehensive_cached[$size])) {
                            $filtered_result[$size] = $comprehensive_cached[$size];
                        } else {
                            $missing_sizes[] = $size;
                        }
                    }

                    // Include metadata if present
                    if (isset($comprehensive_cached['_stats'])) {
                        $filtered_result['_stats'] = $comprehensive_cached['_stats'];
                    }
                    if (isset($comprehensive_cached['_photo_info'])) {
                        $filtered_result['_photo_info'] = $comprehensive_cached['_photo_info'];
                    }
                    if (isset($comprehensive_cached['_rotation'])) {
                        $filtered_result['_rotation'] = $comprehensive_cached['_rotation'];
                    }

                    // Only return cached data if we have ALL requested sizes
                    // If we're missing any sizes, proceed to individual API call below
                    if (empty($missing_sizes) && !empty($filtered_result)) {
                        return $filtered_result;
                    }

                    // If we have SOME sizes but not all, fall through to individual API call
                    // This ensures cache warmer fetches ALL sizes including larger ones
                }
            }
        }

        // Build cache key for size-specific cache
        $requested_sizes_key = md5(implode(',', $requested_sizes));
        $cache_suffix = ['dims', $photo_id, $requested_sizes_key];

        // Try size-specific cache key (skip if force refresh)
        $base_cache_key = $cache_suffix;
        if (!$force_refresh) {
            $cached_result = self::get($base_cache_key);
            if (is_array($cached_result)) {
                // Check if this is a negative cache entry (photo not found/private)
                if (isset($cached_result['not_found']) && $cached_result['not_found']) {
                    return []; // Return empty array, don't retry API call
                }
                if (!empty($cached_result)) {
                    return $cached_result;
                }
            }
        }

        // SIMPLIFIED: Cache versioning removed - causes issues when cache is cleared
        // After cache clearing, we should ALWAYS fetch fresh data from Flickr API
        // The base cache key is sufficient for our needs

        // Fetch from API
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return [];
        }

        // Backoff for recent failures on this photo
        $backoff_key = ['backoff', 'photo_sizes', $photo_id];
        $backoff_until = get_transient(self::key($backoff_key));
        if ($backoff_until && time() < (int) $backoff_until) {
            return ['rate_limited' => true];
        }

        // Check API quota before making call
        if (!self::can_make_api_call()) {
            return ['rate_limited' => true];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('Flickr: Making individual API call for photo %s to get all sizes', $photo_id));
        }

        $api_url = add_query_arg([
            'method' => 'flickr.photos.getSizes',
            'api_key' => $api_key,
            'photo_id' => $photo_id,
            'format' => 'json',
            'nojsoncallback' => 1,
        ], 'https://api.flickr.com/services/rest/');

        $max_retries = 2;
        $retry_delay = 2; // seconds
        $response = null;

        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            $response = wp_remote_get($api_url, [
                'timeout' => 30,
                'user-agent' => 'WordPress Flickr Justified Block'
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                break; // Success, exit loop
            }

            if ($attempt < $max_retries) {
                sleep($retry_delay);
            }
        }

        // Track API call
        self::increment_api_calls();

        if (is_wp_error($response)) {
            // Cache negative result to prevent repeated failed API calls
            self::set($base_cache_key, ['not_found' => true]);
            // Short backoff on transport failures
            set_transient(self::key($backoff_key), time() + 300, 300);
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Build context string for error logging
        $context = 'Photo ID: ' . $photo_id;
        if (!empty($page_url)) {
            $context .= ' | Photo URL: ' . $page_url;
        }

        // Add current post/page context if available
        $post_id = get_the_ID();
        // Fall back to global set during AJAX requests
        if (!$post_id && isset($GLOBALS['flickr_justified_current_post_id'])) {
            $post_id = $GLOBALS['flickr_justified_current_post_id'];
        }
        if ($post_id) {
            $context .= ' | Post/Page ID: ' . $post_id . ' | URL: ' . get_permalink($post_id);
        }

        // Check for rate limiting
        if (self::is_rate_limited_response($response, $data, $context)) {
            set_transient(self::key($backoff_key), time() + 600, 600);
            return ['rate_limited' => true];
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);

        // Photo not found, deleted, or private
        if ($response_code < 200 || $response_code >= 300) {
            if ($response_code >= 500) {
                set_transient(self::key($backoff_key), time() + 300, 300);
                return ['rate_limited' => true];
            }
            // Cache negative result
            self::set($base_cache_key, ['not_found' => true]);
            return [];
        }

        // Check for Flickr API error
        if (isset($data['stat']) && $data['stat'] === 'fail') {
            if (isset($data['code']) && (int) $data['code'] >= 500) {
                set_transient(self::key($backoff_key), time() + 300, 300);
                return ['rate_limited' => true];
            }
            // Cache negative result
            self::set($base_cache_key, ['not_found' => true]);
            return [];
        }

        if (empty($data['sizes']['size'])) {
            // Cache negative result
            self::set($base_cache_key, ['not_found' => true]);
            return [];
        }

        // Map API sizes to requested sizes with dimensions
        $result = self::map_sizes_with_dimensions($data['sizes']['size'], $requested_sizes);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $available_labels = array_column($data['sizes']['size'], 'label');
            error_log(sprintf('Flickr photo %s: API returned %d sizes: %s',
                $photo_id,
                count($available_labels),
                implode(', ', $available_labels)
            ));
            error_log(sprintf('Flickr photo %s: Mapped %d sizes from API response',
                $photo_id,
                count($result)
            ));
        }

        if (!empty($result)) {
            // ALWAYS fetch and cache metadata (rotation + stats) to ensure it's available
            // This adds 1 API call (photo_info) but ensures rotation data is cached
            // Stats come "free" from the same photo_info response
            $photo_info = self::get_photo_info($photo_id, $force_refresh);

            if (!empty($photo_info)) {
                $result['_photo_info'] = $photo_info;

                // Get stats from photo_info (will fetch fresh if force_refresh)
                $stats = self::get_photo_stats($photo_id);
                if (!empty($stats)) {
                    $result['_stats'] = $stats;
                }

                if (isset($photo_info['rotation'])) {
                    $result['_rotation'] = self::normalize_rotation($photo_info['rotation']);
                }
            }

            // Cache the result with the base key only (no versioning)
            self::set($base_cache_key, $result);
        }

        return $result;
    }

    /**
     * Map Flickr API size responses to requested size keys with dimensions
     */
    private static function map_sizes_with_dimensions($api_sizes, $requested_sizes) {
        // Use centralized size label mapping
        $size_mapping = self::get_size_label_map();

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

        // If it's already a Flickr numeric ID format (e.g., "13122632@N00"), return as-is
        // Flickr numeric IDs can contain digits, @, and letters
        if (is_numeric($username) || preg_match('/^[0-9]+@N[0-9]{2}$/', $username)) {
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

        // Check API quota before making call
        if (!self::can_make_api_call()) {
            return false; // Return false for user ID resolution
        }

        $max_retries = 2;
        $retry_delay = 2; // seconds
        $response = null;

        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            $response = wp_remote_get($api_url, [
                'timeout' => 30,
                'user-agent' => 'WordPress Flickr Justified Block'
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                break; // Success, exit loop
            }

            if ($attempt < $max_retries) {
                sleep($retry_delay);
            }
        }

        // Track API call
        self::increment_api_calls();

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Build context string for error logging
        $context = 'Username: ' . $username;

        // Check for rate limiting
        if (self::is_rate_limited_response($response, $data, $context)) {
            return false; // Return false for user ID resolution
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);
        if ($response_code < 200 || $response_code >= 300) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("resolve_user_id: Bad response code $response_code for username $username");
            }
            return false;
        }

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['user']['id'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("resolve_user_id: JSON error or missing user ID for username $username");
            }
            return false;
        }

        $user_id = $data['user']['id'];
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("resolve_user_id: Successfully resolved $username to $user_id, caching...");
        }
        self::set($cache_key, $user_id); // Use configured cache duration

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("resolve_user_id: Returning $user_id");
        }
        return $user_id;
    }

    /**
     * Cache photo data from album response to avoid per-photo API calls
     *
     * This method caches comprehensive photo data obtained from photosets.getPhotos
     * so that subsequent get_photo_sizes() calls can use cached data instead of
     * making individual API calls. This is a MASSIVE optimization for large albums.
     *
     * @param array $photo Photo data from photosets.getPhotos response
     * @param string $photo_id Numeric photo ID
     */
    private static function cache_photo_data_from_album_response($photo, $photo_id) {
        if (empty($photo) || !is_array($photo)) {
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            static $logged_full_keys = false;
            if (!$logged_full_keys) {
                $all_keys = is_array($photo) ? implode(', ', array_keys($photo)) : 'not_array';
                error_log('Flickr cache_photo_data FULL KEYS: ' . $all_keys);
                $logged_full_keys = true;
            }
        }

        // Use centralized size mapping
        $size_map = self::get_size_suffix_map();

        // Build size data from available URLs in the response
        $sizes_data = [];
        foreach ($size_map as $suffix => $size_name) {
            $url_key = 'url_' . $suffix;
            $width_key = 'width_' . $suffix;
            $height_key = 'height_' . $suffix;

            if (isset($photo[$url_key]) && !empty($photo[$url_key])) {
                $sizes_data[$size_name] = [
                    'url' => esc_url_raw($photo[$url_key]),
                    'width' => isset($photo[$width_key]) ? (int) $photo[$width_key] : 0,
                    'height' => isset($photo[$height_key]) ? (int) $photo[$height_key] : 0,
                ];
            }
        }

        // If we don't have any size data, nothing to cache
        if (empty($sizes_data)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flickr cache: No size data to cache for photo ' . $photo_id);
            }
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr cache: Caching photo ' . $photo_id . ' with ' . count($sizes_data) . ' sizes');
        }

        // Extract stats if available
        $stats = [];
        $stats['views'] = max(0, (int) ($photo['views'] ?? 0));
        if (isset($photo['count_comments'])) {
            $stats['comments'] = max(0, (int) $photo['count_comments']);
        }
        if (isset($photo['count_faves'])) {
            $stats['favorites'] = max(0, (int) $photo['count_faves']);
        }
        if (isset($photo['lastupdate'])) {
            $timestamp = (int) $photo['lastupdate'];
            if ($timestamp > 0) {
                $stats['date'] = gmdate('Y-m-d', $timestamp);
            }
        }

        // Build cache data structure (matches format from get_photo_sizes)
        $cache_data = $sizes_data;

        if (!empty($stats)) {
            $cache_data['_stats'] = $stats;
        }

        // Add lightweight photo_info payload so render can use titles/descriptions without extra calls.
        $photo_info = [];
        if (isset($photo['title'])) {
            $photo_info['title'] = [
                '_content' => sanitize_text_field((string) $photo['title']),
            ];
        }
        if (isset($photo['description'])) {
            if (is_array($photo['description']) && isset($photo['description']['_content'])) {
                $photo_info['description'] = [
                    '_content' => sanitize_textarea_field((string) $photo['description']['_content']),
                ];
            } else {
                $photo_info['description'] = [
                    '_content' => sanitize_textarea_field((string) $photo['description']),
                ];
            }
        }

        // Owner/user meta if present
        if (isset($photo['owner']) || isset($photo['ownername'])) {
            $photo_info['owner'] = [
                'nsid' => isset($photo['owner']) ? sanitize_text_field((string) $photo['owner']) : '',
                'username' => isset($photo['ownername']) ? sanitize_text_field((string) $photo['ownername']) : '',
            ];
        }

        // Dates from album payload
        $dates = [];
        if (isset($photo['lastupdate'])) {
            $dates['lastupdate'] = (int) $photo['lastupdate'];
        }
        if (isset($photo['dateupload'])) {
            $dates['posted'] = (int) $photo['dateupload'];
        }
        if (isset($photo['datetaken'])) {
            $dates['taken'] = sanitize_text_field((string) $photo['datetaken']);
        }
        if (!empty($dates)) {
            $photo_info['dates'] = $dates;
        }

        // Media type if available
        if (isset($photo['media'])) {
            $photo_info['media'] = sanitize_text_field((string) $photo['media']);
        }

        if (!empty($photo_info)) {
            $cache_data['_photo_info'] = $photo_info;
        }

        // Note: Rotation is NOT available in album response, will need separate call if needed
        // But that's okay - rotation is relatively rare and we cache it when fetched

        // Cache under the comprehensive cache key that matches get_photo_sizes() lookups
        $comprehensive_sizes = self::get_comprehensive_size_list();
        $requested_sizes_key = md5(implode(',', $comprehensive_sizes));
        $cache_key = ['dims', $photo_id, $requested_sizes_key];

        // Cache for the configured duration
        self::set($cache_key, $cache_data);
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

        // Check API quota before making call
        if (!self::can_make_api_call()) {
            return ['rate_limited' => true];
        }

        $max_retries = 2;
        $retry_delay = 2; // seconds
        $response = null;

        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            $response = wp_remote_get($api_url, [
                'timeout' => 30,
                'user-agent' => 'WordPress Flickr Justified Block'
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                break; // Success, exit loop
            }

            if ($attempt < $max_retries) {
                sleep($retry_delay);
            }
        }

        // Track API call
        self::increment_api_calls();

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Build context string for error logging
        $context = 'Album ID: ' . $photoset_id . ' | User: ' . $user_id . ' | Album URL: https://flickr.com/photos/' . $user_id . '/albums/' . $photoset_id;

        // Add current post/page context if available
        $post_id = get_the_ID();
        // Fall back to global set during AJAX requests
        if (!$post_id && isset($GLOBALS['flickr_justified_current_post_id'])) {
            $post_id = $GLOBALS['flickr_justified_current_post_id'];
        }
        if ($post_id) {
            $context .= ' | Post/Page ID: ' . $post_id . ' | URL: ' . get_permalink($post_id);
        }

        // Check for rate limiting
        if (self::is_rate_limited_response($response, $data, $context)) {
            return ['rate_limited' => true];
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);
        if ($response_code < 200 || $response_code >= 300) {
            return false;
        }

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['photoset'])) {
            return false;
        }

        $photoset_info = [
            'title' => isset($data['photoset']['title']['_content']) ? sanitize_text_field($data['photoset']['title']['_content']) : '',
            'description' => isset($data['photoset']['description']['_content']) ? sanitize_textarea_field($data['photoset']['description']['_content']) : '',
            'photo_count' => isset($data['photoset']['count_photos']) ? intval($data['photoset']['count_photos']) : 0,
            'video_count' => isset($data['photoset']['count_videos']) ? intval($data['photoset']['count_videos']) : 0,
            'views' => isset($data['photoset']['count_views']) ? intval($data['photoset']['count_views']) : 0,
            'comments' => isset($data['photoset']['count_comments']) ? intval($data['photoset']['count_comments']) : 0,
        ];

        self::set($cache_key, $photoset_info); // Use configured cache duration

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
        if (is_array($cached)) {
            // Check if this is a negative cache entry (album not found/private)
            if (isset($cached['not_found']) && $cached['not_found']) {
                return self::empty_photoset_result($page);
            }
            if (!empty($cached) && isset($cached['photos'])) {
                return $cached;
            }
        }

        // Fetch from API
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return self::empty_photoset_result($page);
        }

        // Request comprehensive photo data in the album call to avoid per-photo API calls.
        // This dramatically reduces API usage: 1 call per 500 photos instead of 1000+ calls.
        // Stick to the documented extras for flickr.photosets.getPhotos.
        $extras = [
            'license',
            'date_upload',
            'date_taken',
            'owner_name',
            'icon_server',
            'original_format',
            'last_update',
            'geo',
            'tags',
            'machine_tags',
            // Dimensions for all available sizes
            'o_dims',           // Original dimensions (width_o, height_o)
            'views',
            'media',
            'path_alias',

            // URLs for multiple sizes - only the documented ones supported by photosets.getPhotos.
            'url_sq',  // Square 75x75
            'url_t',   // Thumbnail 100 on longest side
            'url_s',   // Small 240 on longest side
            'url_m',   // Medium 500 on longest side
            'url_o',   // Original (requires permission)

            // Statistics (views, comments available; favorites requires separate call)
            'views',
            'count_comments',   // Comment count
            'count_faves',      // Favorites count

            // Metadata
            'date_upload', 'date_taken', 'last_update',
            'description',
            'tags',
            'original_format',
            'media',
        ];

        $api_url = add_query_arg([
            'method' => 'flickr.photosets.getPhotos',
            'api_key' => $api_key,
            'photoset_id' => $photoset_id,
            'user_id' => $resolved_user_id,
            'per_page' => $per_page,
            'page' => $page,
            'extras' => implode(',', $extras),
            'format' => 'json',
            'nojsoncallback' => 1,
        ], 'https://api.flickr.com/services/rest/');

        // Check API quota before making call
        if (!self::can_make_api_call()) {
            return ['rate_limited' => true];
        }

        $max_retries = 2;
        $retry_delay = 2; // seconds
        $response = null;

        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            $response = wp_remote_get($api_url, [
                'timeout' => 30,
                'user-agent' => 'WordPress Flickr Justified Block'
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                break; // Success, exit loop
            }

            if ($attempt < $max_retries) {
                sleep($retry_delay);
            }
        }

        // Track API call
        self::increment_api_calls();

        if (is_wp_error($response)) {
            return self::empty_photoset_result($page);
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return self::empty_photoset_result($page);
        }

        $data = json_decode($body, true);

        // Build context string for error logging
        $context = 'Album ID: ' . $photoset_id . ' | User: ' . $user_id . ' | Album URL: https://flickr.com/photos/' . $user_id . '/albums/' . $photoset_id;

        // Add current post/page context if available
        $post_id = get_the_ID();
        // Fall back to global set during AJAX requests
        if (!$post_id && isset($GLOBALS['flickr_justified_current_post_id'])) {
            $post_id = $GLOBALS['flickr_justified_current_post_id'];
        }
        if ($post_id) {
            $context .= ' | Post/Page ID: ' . $post_id . ' | URL: ' . get_permalink($post_id);
        }

        // Check for rate limiting
        if (self::is_rate_limited_response($response, $data, $context)) {
            return ['rate_limited' => true];
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            self::set($cache_key, ['not_found' => true]);
            return self::empty_photoset_result($page);
        }

        $response_code = (int) wp_remote_retrieve_response_code($response);

        // Album not found, deleted, or private
        if ($response_code < 200 || $response_code >= 300) {
            if ($response_code >= 500) {
                return ['rate_limited' => true];
            }
            // Cache negative result
            self::set($cache_key, ['not_found' => true]);
            return self::empty_photoset_result($page);
        }

        // Check for Flickr API error (album deleted, private, permission denied)
        if (isset($data['stat']) && 'fail' === $data['stat']) {
            if (isset($data['code']) && (int) $data['code'] >= 500) {
                return ['rate_limited' => true];
            }
            // Cache negative result
            self::set($cache_key, ['not_found' => true]);
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
                    'video_count' => 0,
                    'views' => 0,
                    'comments' => 0,
                ]); // Use configured cache duration
            }
        }

        // Convert to photo URLs AND cache comprehensive photo data to avoid per-photo API calls
        $photo_urls = [];
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("get_photoset_photos: Processing " . count($data['photoset']['photo']) . " photos from API response");
        }
        foreach ($data['photoset']['photo'] as $photo) {
            if (empty($photo['id']) || !is_string($photo['id'])) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("get_photoset_photos: Skipping photo with invalid ID");
                }
                continue;
            }

            $photo_id = preg_replace('/[^0-9]/', '', $photo['id']);
            if (empty($photo_id)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("get_photoset_photos: Skipping photo with empty numeric ID");
                }
                continue;
            }

            $photo_url = 'https://flickr.com/photos/' . rawurlencode($user_id) . '/' . $photo_id . '/';
            $photo_urls[] = $photo_url;

            // OPTIMIZATION: Cache photo data from album response to eliminate per-photo API calls
            // This is a HUGE performance win for large albums
            self::cache_photo_data_from_album_response($photo, $photo_id);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("get_photoset_photos: Built " . count($photo_urls) . " photo URLs");
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
