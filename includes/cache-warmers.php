<?php

/**
 * Cache warmer integration for Flickr Justified Block.
 *
 * @package FlickrJustifiedBlock
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coordinate WP-Cron and WP-CLI cache warmers for known Flickr URLs.
 */
class FlickrJustifiedCacheWarmer {
    private const OPTION_KNOWN_URLS = 'flickr_justified_known_flickr_urls';
    private const OPTION_QUEUE = 'flickr_justified_cache_warmer_queue';
    private const OPTION_FAILURES = 'flickr_justified_cache_warmer_failures';
    private const OPTION_LOCK = 'flickr_justified_cache_warmer_lock';
    private const OPTION_PAUSE_UNTIL = 'flickr_justified_cache_warmer_pause_until';
    private const CRON_HOOK = 'flickr_justified_run_cache_warmer';
    private const CRON_SCHEDULE = 'flickr_justified_cache_warm_interval';
    private const FAST_DELAY = 60; // seconds.
    private const SLOW_DELAY = 300; // seconds.
    private const LOCK_TTL = 600; // seconds.
    private const MAX_FAILURES = 8;

    /**
     * Bootstrap hooks.
     */
    public static function init() {
        add_filter('cron_schedules', [__CLASS__, 'register_cron_schedule']);
        add_action(self::CRON_HOOK, [__CLASS__, 'process_queue']);
        add_action('init', [__CLASS__, 'maybe_schedule_recurring_event']);

        if (defined('WP_CLI') && WP_CLI) {
            self::register_cli_command();
        }
    }

    /**
     * Determine whether the warmer is enabled in settings.
     */
    public static function is_enabled() {
        return (bool) flickr_justified_get_admin_setting('is_cache_warmer_enabled', true);
    }

    /**
     * Determine whether slow mode is enabled.
     */
    private static function is_slow_mode() {
        return (bool) flickr_justified_get_admin_setting('is_cache_warmer_slow_mode', true);
    }

    /**
     * Retrieve configured batch size.
     */
    private static function get_batch_size() {
        $size = (int) flickr_justified_get_admin_setting('get_cache_warmer_batch_size', 5);
        if ($size < 1) {
            $size = 1;
        }
        return min($size, 25);
    }

    /**
     * Acquire a short-lived lock to prevent overlapping runs.
     */
    private static function acquire_lock() {
        $expires = time() + self::LOCK_TTL;

        // Prefer object cache for atomic add when available.
        if (function_exists('wp_cache_add')) {
            if (wp_cache_add(self::OPTION_LOCK, 1, '', self::LOCK_TTL)) {
                return true;
            }
        }

        $existing = get_option(self::OPTION_LOCK);
        if ($existing && (int) $existing > time()) {
            return false;
        }

        update_option(self::OPTION_LOCK, $expires, false);
        return true;
    }

    /**
     * Release the lock.
     */
    private static function release_lock() {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_LOCK, '');
        }
        delete_option(self::OPTION_LOCK);
    }

    /**
     * Register the recurring cron interval based on cache duration.
     */
    public static function register_cron_schedule($schedules) {
        if (!is_array($schedules)) {
            $schedules = [];
        }

        // Use the configured delay interval (fast or slow mode) for recurring schedule
        // This ensures the warmer runs frequently to process the queue in small batches
        $interval = self::get_delay_interval();

        $schedules[self::CRON_SCHEDULE] = [
            'interval' => $interval,
            'display' => __('Flickr Justified cache warmer', 'flickr-justified-block'),
        ];

        return $schedules;
    }

    /**
     * Ensure the recurring cron event exists when enabled.
     */
    public static function maybe_schedule_recurring_event() {
        if (!self::is_enabled()) {
            self::clear_scheduled_events();
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    /**
     * Clear scheduled cron events.
     */
    public static function clear_scheduled_events() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        while (false !== $timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    /**
     * Activation hook callback.
     */
    public static function handle_activation() {
        self::rebuild_known_urls();
        self::prime_queue_from_known_urls(true);
        self::maybe_schedule_recurring_event();
        if (self::is_enabled()) {
            self::schedule_next_batch(0);
        }
    }

    /**
     * Deactivation hook callback.
     */
    public static function handle_deactivation() {
        self::clear_scheduled_events();
    }

    /**
     * Process the cache queue from cron or CLI.
     *
     * @param bool $process_all When true, warm the entire queue at once.
     * @param bool $bypass_lock When true, skip the overlap lock (CLI only).
     * @param int|null $max_seconds Optional max runtime before yielding.
     * @return int Number of URLs processed.
     */
    public static function process_queue($process_all = false, $bypass_lock = false, $max_seconds = null) {
        $start_time = microtime(true);
        $should_honor_pause = !$process_all || !$bypass_lock;

        // Respect pause window after rate limits unless forced.
        if ($should_honor_pause) {
            $pause_until = self::get_pause_until();
            if ($pause_until > time()) {
                return 0;
            }
        }

        // Prevent overlapping runs (cron + CLI). If lock is held, skip quietly unless bypassed.
        if (!$bypass_lock && !self::acquire_lock()) {
            return 0;
        }

        try {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'Flickr cache warmer: process_queue() called (enabled=%s, process_all=%s, bypass_lock=%s)',
                    self::is_enabled() ? 'yes' : 'no',
                    $process_all ? 'yes' : 'no',
                    $bypass_lock ? 'yes' : 'no'
                ));
            }

            if (!self::is_enabled() && !$process_all) {
                return 0;
            }

            $queue = self::get_queue();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'Flickr cache warmer: Queue has %d items',
                    count($queue)
                ));
            }

            if (empty($queue)) {
                $queue = self::prime_queue_from_known_urls();
                if (empty($queue)) {
                    return 0;
                }
            }

            $batch_size = $process_all ? count($queue) : self::get_batch_size();
            $processed = 0;
            $attempted = 0;
            $remaining = [];
            $rate_limited = false;
            $last_error = '';

            foreach ($queue as $queue_item) {
                if (!$process_all && $attempted >= $batch_size) {
                    $remaining[] = $queue_item;
                    continue;
                }

                // Support both old format (string) and new format (array with url and page)
                if (is_array($queue_item)) {
                    $url = isset($queue_item['url']) ? $queue_item['url'] : '';
                    $page = isset($queue_item['page']) ? (int) $queue_item['page'] : 1;
                } else {
                    $url = $queue_item;
                    $page = 1;
                }

                if (empty($url)) {
                    continue;
                }

                $queue_key = self::normalize_queue_key($queue_item);
                $backoff_until = self::get_backoff_until($queue_key);
                if ($backoff_until > time()) {
                    // Skip this item for now but try to fill the batch with later items.
                    $remaining[] = $queue_item;
                    continue;
                }

                $attempted++;

                $result = self::warm_url($url, $page);

                if ($result === 'rate_limited') {
                    // Rate limited - stop processing and keep this URL for retry
                    $rate_limited = true;
                    $remaining[] = $queue_item;
                    self::clear_failure($queue_key);
                    self::set_pause_until(time() + HOUR_IN_SECONDS);

                    // Add all remaining URLs back to queue
                    for ($i = $attempted; $i < count($queue); $i++) {
                        $remaining[] = $queue[$i];
                    }

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('Flickr cache warmer: Rate limited at URL ' . $attempted . ', scheduling retry in 1 hour');
                    }
                    break;
                } elseif (is_array($result) && isset($result['success'])) {
                    // Album result with pagination info
                    if ($result['success']) {
                        $processed++;
                        self::clear_failure($queue_key);

                        // If we timed out on this page, retry it before moving forward.
                        if (!empty($result['needs_retry_page'])) {
                            array_unshift($remaining, [
                                'url' => $url,
                                'page' => $result['current_page'],
                            ]);

                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log(sprintf(
                                    'Flickr cache warmer: Re-queuing current page %d for album due to timeout: %s',
                                    $result['current_page'],
                                    $url
                                ));
                            }
                        } elseif (!empty($result['has_more_pages']) && $result['current_page'] < $result['total_pages']) {
                            // If there are more pages, add the next page to the FRONT of the queue
                            // This ensures we complete one album before moving to the next
                            $next_page = $result['current_page'] + 1;
                            array_unshift($remaining, [
                                'url' => $url,
                                'page' => $next_page,
                            ]);

                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log(sprintf(
                                    'Flickr cache warmer: Queuing page %d/%d for album (priority): %s',
                                    $next_page,
                                    $result['total_pages'],
                                    $url
                                ));
                            }
                        }
                    } else {
                        // Failed - keep in queue for retry unless max failures exceeded
                        $drop = self::mark_failure($queue_key);
                        if (!$drop) {
                            $remaining[] = $queue_item;
                        }
                        $last_error = 'album_fail';
                    }
                } elseif ($result) {
                    // Simple success (photo URL)
                    $processed++;
                    self::clear_failure($queue_key);
                    continue;
                } else {
                    // Failed for other reasons - keep in queue for retry
                    $drop = self::mark_failure($queue_key);
                    if (!$drop) {
                        $remaining[] = $queue_item;
                    }
                    $last_error = 'photo_fail';
                }

                if (null !== $max_seconds && (microtime(true) - $start_time) >= $max_seconds) {
                    // Put unprocessed items back to the queue
                    for ($i = $attempted; $i < count($queue); $i++) {
                        $remaining[] = $queue[$i];
                    }
                    break;
                }
            }

            self::save_queue($remaining);

            if (!$process_all && !empty($remaining)) {
                if ($rate_limited) {
                    // Schedule retry in 1 hour when rate limited
                    self::schedule_next_batch(HOUR_IN_SECONDS);
                } else {
                    // Normal delay between batches
                    self::schedule_next_batch(self::get_delay_interval());
                }
            }
            // On success after a pause window, clear the pause.
            if (!$rate_limited && $processed > 0) {
                self::clear_pause_until();
            }

            self::set_last_run($processed, $rate_limited, $last_error);
            return $processed;
        } finally {
            self::release_lock();
        }
    }

    /**
     * Warm a single Flickr URL (used by cron and manual warming).
     * For albums, this warms a small batch of photos to prevent timeouts.
     *
     * @param string $url The Flickr URL to warm.
     * @param int $page The page number for album URLs (default 1).
     * @return bool|string|array True on success, 'rate_limited' on rate limit, false on failure,
     *                           or album array with 'success', 'has_more_pages', 'needs_retry_page'.
     */
    public static function warm_url($url, $page = 1) {
        if (!is_string($url) || '' === trim($url)) {
            return false;
        }

        $url = trim($url);
        $page = max(1, (int) $page);
        $start_time = microtime(true);
        $max_duration = (int) apply_filters('flickr_justified_cache_warmer_max_seconds', 20);
        $max_duration = max(5, $max_duration);
        $sleep_seconds = (float) apply_filters('flickr_justified_cache_warmer_sleep_seconds', 1.0);
        // Clamp to sane bounds: 0-2s
        if ($sleep_seconds < 0) {
            $sleep_seconds = 0.0;
        } elseif ($sleep_seconds > 2) {
            $sleep_seconds = 2.0;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = 'Flickr warm_url processing: ' . $url;
            if ($page > 1) {
                $log_message .= ' (page ' . $page . ')';
            }
            error_log($log_message);
        }

        // Check if this is a photo URL
        if (function_exists('flickr_justified_is_flickr_photo_url') && flickr_justified_is_flickr_photo_url($url)) {
            // Extract photo ID from URL
            if (!preg_match('#flickr\.com/photos/[^/]+/(\d+)#', $url, $matches)) {
                return false;
            }
            $photo_id = $matches[1];

            $success = false;

            try {
                // Warm photo sizes and metadata using cache.php directly
                $available_sizes = flickr_justified_get_available_flickr_sizes(true);
                $data = FlickrJustifiedCache::get_photo_sizes($photo_id, $url, $available_sizes, true, false);

                // Check for rate limiting
                if (is_array($data) && isset($data['rate_limited']) && $data['rate_limited']) {
                    return 'rate_limited';
                }

                if (!empty($data)) {
                    $success = true;
                }

                // If rotation is missing, warm photo info to capture it.
                if (!isset($data['_rotation'])) {
                    $info = FlickrJustifiedCache::get_photo_info($photo_id, false);
                    if (is_array($info) && isset($info['rotation'])) {
                        $success = true;
                    }
                }

                // Warm photo stats using cache.php directly
                $stats = FlickrJustifiedCache::get_photo_stats($photo_id);

                // Check for rate limiting in stats call
                if (is_array($stats) && isset($stats['rate_limited']) && $stats['rate_limited']) {
                    return 'rate_limited';
                }

                if (!empty($stats)) {
                    $success = true;
                }

                // Add delay to respect Flickr's rate limit (3,600 calls/hour = 1 per second)
                // Each photo can make 2 API calls (sizes + stats); filterable for tuning.
                if ($sleep_seconds > 0) {
                    usleep((int) ($sleep_seconds * 1000000));
                }
            } catch (Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Flickr warm_url photo error: ' . $e->getMessage());
                }
                return false;
            }

            return $success;
        }

        // Check if this is an album URL
        if (!function_exists('flickr_justified_parse_set_url')) {
            return false;
        }

        $set_info = flickr_justified_parse_set_url($url);
        if (!$set_info || empty($set_info['user_id']) || empty($set_info['photoset_id'])) {
            return false;
        }

        try {
            // Use the configured batch size to limit photos fetched from an album in one go.
            // This prevents server timeouts on huge albums.
            $per_page = self::get_batch_size();
            $success = false;
            $available_sizes = flickr_justified_get_available_flickr_sizes(true);

            // Fetch the specified page of the album, limited by the batch size.
            $result = FlickrJustifiedCache::get_photoset_photos($set_info['user_id'], $set_info['photoset_id'], $page, $per_page);

            // Check for rate limiting in photoset call
            if (is_array($result) && isset($result['rate_limited']) && $result['rate_limited']) {
                return 'rate_limited';
            }

            if (empty($result) || empty($result['photos']) || !is_array($result['photos'])) {
                return false; // No photos found or error
            }

            $photos = array_values($result['photos']);
            $has_more_pages = isset($result['has_more']) ? (bool) $result['has_more'] : false;
            $current_page = isset($result['page']) ? (int) $result['page'] : $page;
            $total_pages = isset($result['pages']) ? (int) $result['pages'] : 1;
            $total_photos = isset($result['total']) ? (int) $result['total'] : count($photos);
            $timed_out = false;

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'Flickr warm_url album page %d/%d: %d photos, %d total photos in album',
                    $current_page,
                    $total_pages,
                    count($photos),
                    $total_photos
                ));
            }

            // Warm each photo from the current page.
            foreach ($photos as $photo_url) {
                // Bail out if this run has consumed its time budget.
                if ((microtime(true) - $start_time) >= $max_duration) {
                    $timed_out = true;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('Flickr warm_url album: max duration reached, deferring remaining photos');
                    }
                    break;
                }

                $photo_url = trim((string) $photo_url);
                if ('' === $photo_url) {
                    continue;
                }

                if (!preg_match('#flickr\.com/photos/[^/]+/(\d+)#', $photo_url, $matches)) {
                    continue;
                }
                $photo_id = $matches[1];

                // Warm photo sizes and metadata
                $data = FlickrJustifiedCache::get_photo_sizes($photo_id, $photo_url, $available_sizes, true, false);
                if (is_array($data) && isset($data['rate_limited']) && $data['rate_limited']) {
                    return 'rate_limited';
                }

                // If rotation is missing, warm photo info to capture it.
                if (!isset($data['_rotation'])) {
                    $info = FlickrJustifiedCache::get_photo_info($photo_id, false);
                    if (is_array($info) && isset($info['rotation'])) {
                        $success = true;
                    }
                }

                // Warm photo stats
                $stats = FlickrJustifiedCache::get_photo_stats($photo_id);
                if (is_array($stats) && isset($stats['rate_limited']) && $stats['rate_limited']) {
                    return 'rate_limited';
                }

                // Mark as success if at least one photo was processed
                if (!empty($data)) {
                    $success = true;
                }

                // Add delay between photos to respect Flickr's rate limit (3,600 calls/hour = 1 per second)
                // Each photo can make 2 API calls (sizes + stats); filterable for tuning.
                if ($sleep_seconds > 0) {
                    usleep((int) ($sleep_seconds * 1000000));
                }

                if ((microtime(true) - $start_time) >= $max_duration) {
                    $timed_out = true;
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('Flickr warm_url album: max duration reached mid-page, deferring remaining photos');
                    }
                    break;
                }
            }

            // Return pagination info so caller can queue next page if needed
            return [
                'success' => $success,
                // If we timed out mid-page, force has_more_pages so the remainder gets retried
                'has_more_pages' => ($has_more_pages || $timed_out),
                'needs_retry_page' => $timed_out,
                'current_page' => $current_page,
                'total_pages' => $total_pages,
            ];
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flickr warm_url album error: ' . $e->getMessage() . ' for URL: ' . $url);
            }
            return false;
        }
    }


    /**
     * Schedule the next batch run.
     */
    public static function schedule_next_batch($delay = null) {
        if (!$delay && 0 !== $delay) {
            $delay = self::get_delay_interval();
        }

        $delay = max(0, (int) $delay);

        // Apply small jitter to avoid stampeding cron starts.
        $jitter_fraction = (float) apply_filters('flickr_justified_cache_warmer_schedule_jitter_fraction', 0.1);
        if ($jitter_fraction < 0) {
            $jitter_fraction = 0.0;
        } elseif ($jitter_fraction > 0.5) {
            $jitter_fraction = 0.5;
        }
        if ($jitter_fraction > 0) {
            $rand = mt_rand() / max(mt_getrandmax(), 1);
            $factor = 1 + (($rand * 2 * $jitter_fraction) - $jitter_fraction);
            $delay = (int) round($delay * $factor);
        }

        $timestamp = time() + $delay;

        $existing = wp_next_scheduled(self::CRON_HOOK);
        if (false !== $existing && $existing <= $timestamp) {
            return;
        }

        wp_schedule_single_event($timestamp, self::CRON_HOOK);
    }

    /**
     * Determine delay between batches.
     */
    private static function get_delay_interval() {
        return self::is_slow_mode() ? self::SLOW_DELAY : self::FAST_DELAY;
    }

    /**
     * Handle post saves to extract block URLs.
     */
    public static function handle_post_save($post_id, $post, $update) {
        if (!is_object($post) || !isset($post->post_type)) {
            return;
        }

        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if (in_array($post->post_status, ['auto-draft', 'inherit'], true)) {
            return;
        }

        $urls = self::extract_block_urls($post->post_content);
        self::update_known_urls_for_post($post_id, $urls);
        self::prime_queue_from_known_urls(true);

        if (!empty($urls) && self::is_enabled()) {
            self::schedule_next_batch();
        }
    }

    /**
     * Handle post deletion to remove stored URLs.
     */
    public static function handle_post_deletion($post_id) {
        $map = self::get_known_url_map();
        if (isset($map[$post_id])) {
            unset($map[$post_id]);
            self::save_known_url_map($map);
            self::prime_queue_from_known_urls(true);
        }
    }

    /**
     * Extract Flickr URLs from block content.
     */
    private static function extract_block_urls($content) {
        if (!function_exists('parse_blocks') || !is_string($content) || '' === $content) {
            return [];
        }

        $blocks = parse_blocks($content);
        $urls = [];

        foreach ($blocks as $block) {
            $urls = array_merge($urls, self::extract_urls_from_block($block));
        }

        $urls = array_filter(array_map('trim', $urls));
        $urls = array_unique($urls);

        return array_values($urls);
    }

    /**
     * Recursive helper to walk parsed blocks.
     */
    private static function extract_urls_from_block($block) {
        $urls = [];

        if (!is_array($block)) {
            return $urls;
        }

        if (isset($block['blockName']) && 'flickr-justified/block' === $block['blockName']) {
            $urls = array_merge($urls, self::split_urls_from_attribute($block));
        }

        if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $inner_block) {
                $urls = array_merge($urls, self::extract_urls_from_block($inner_block));
            }
        }

        return $urls;
    }

    /**
     * Split URLs from block attribute.
     */
    private static function split_urls_from_attribute($block) {
        $urls = [];

        if (!isset($block['attrs']) || !is_array($block['attrs'])) {
            return $urls;
        }

        $raw_urls = isset($block['attrs']['urls']) ? $block['attrs']['urls'] : '';
        if (!is_string($raw_urls) || '' === $raw_urls) {
            return $urls;
        }

        $candidates = preg_split('/\r\n|\r|\n/', $raw_urls);
        if (!is_array($candidates)) {
            return $urls;
        }

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ('' === $candidate) {
                continue;
            }

            $is_photo = function_exists('flickr_justified_is_flickr_photo_url') && flickr_justified_is_flickr_photo_url($candidate);
            $is_set = function_exists('flickr_justified_parse_set_url') && flickr_justified_parse_set_url($candidate);

            if ($is_photo || $is_set) {
                $urls[] = $candidate;
            }
        }

        return $urls;
    }

    /**
     * Update known URLs for a specific post.
     */
    private static function update_known_urls_for_post($post_id, $urls) {
        $map = self::get_known_url_map();

        if (!empty($urls)) {
            $map[$post_id] = array_values(array_unique($urls));
        } else {
            unset($map[$post_id]);
        }

        self::save_known_url_map($map);
    }

    /**
     * Retrieve the stored known URL map.
     */
    private static function get_known_url_map() {
        $map = get_option(self::OPTION_KNOWN_URLS, []);
        if (!is_array($map)) {
            $map = [];
        }
        return $map;
    }

    /**
     * Persist the known URL map.
     */
    private static function save_known_url_map($map) {
        if (empty($map)) {
            delete_option(self::OPTION_KNOWN_URLS);
            return;
        }

        update_option(self::OPTION_KNOWN_URLS, $map, false);
    }

    /**
     * Gather all known URLs from the map.
     */
    private static function get_all_known_urls() {
        $map = self::get_known_url_map();

        // Auto-rebuild if empty - prevents cache warmer from getting stuck
        if (empty($map)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flickr cache warmer: known_urls empty, auto-rebuilding...');
            }
            $map = self::rebuild_known_urls();
        }

        $urls = [];

        foreach ($map as $post_urls) {
            if (is_array($post_urls)) {
                $urls = array_merge($urls, $post_urls);
            }
        }

        $urls = array_filter(array_map('trim', $urls));
        $urls = array_unique($urls);

        return array_values($urls);
    }

    /**
     * Prime the queue from known URLs.
     *
     * @param bool $force When true, merge new URLs with existing queue entries.
     * @return array Resulting queue.
     */
    private static function prime_queue_from_known_urls($force = false) {
        $current_queue = self::get_queue();

        if (!$force && !empty($current_queue)) {
            self::cleanup_failure_map($current_queue);
            return $current_queue;
        }

        $urls = self::get_all_known_urls();

        if (!$force) {
            // Simple case: queue is empty, just use the new URLs
            self::save_queue($urls);
            self::cleanup_failure_map($urls);
            return $urls;
        }

        // When forcing, preserve in-progress paginated entries
        // Build maps of what's currently in the queue
        $urls_in_queue = []; // Maps URL => true if ANY page is in queue
        $paginated_entries = []; // Keep all paginated entries (page > 1)

        foreach ($current_queue as $item) {
            if (is_array($item) && isset($item['url'])) {
                $urls_in_queue[$item['url']] = true;
                // Keep paginated entries (page > 1) that are still in known URLs
                if (isset($item['page']) && $item['page'] > 1) {
                    if (in_array($item['url'], $urls, true)) {
                        $paginated_entries[] = $item;
                    }
                }
            } elseif (is_string($item)) {
                $urls_in_queue[$item] = true;
            }
        }

        // Start with preserved paginated entries
        $merged_queue = $paginated_entries;

        // Add all URLs from known URLs list
        foreach ($urls as $url) {
            // Check if this URL (any page) is already in merged_queue
            $already_queued = false;
            foreach ($merged_queue as $item) {
                $item_url = is_array($item) ? ($item['url'] ?? '') : $item;
                if ($item_url === $url) {
                    $already_queued = true;
                    break;
                }
            }

            // Only add if not already in queue (avoids duplicates and page 1 when page N exists)
            if (!$already_queued) {
                $merged_queue[] = $url;
            }
        }

        self::save_queue($merged_queue);
        self::cleanup_failure_map($merged_queue);
        return $merged_queue;
    }

    /**
     * Retrieve the current queue.
     * Queue items can be either strings (legacy format) or arrays with 'url' and 'page' keys.
     */
    private static function get_queue() {
        $queue = get_option(self::OPTION_QUEUE, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        // Clean and validate queue items
        $queue = array_filter($queue, function($item) {
            if (is_string($item)) {
                return '' !== trim($item);
            } elseif (is_array($item)) {
                return isset($item['url']) && is_string($item['url']) && '' !== trim($item['url']);
            }
            return false;
        });

        // Remove exact duplicates
        $seen = [];
        $queue = array_filter($queue, function($item) use (&$seen) {
            $key = is_array($item) ? ($item['url'] . '_' . (isset($item['page']) ? $item['page'] : 1)) : $item;
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        });

        return array_values($queue);
    }

    /**
     * Normalize queue item to a unique key for tracking failures/backoff.
     */
    private static function normalize_queue_key($item) {
        if (is_array($item)) {
            $url = isset($item['url']) ? trim((string) $item['url']) : '';
            $page = isset($item['page']) ? (int) $item['page'] : 1;
            return $url . '|page:' . $page;
        }

        return trim((string) $item) . '|page:1';
    }

    /**
     * Retrieve failure/backoff map.
     */
    private static function get_failure_map() {
        $map = get_option(self::OPTION_FAILURES, []);
        return is_array($map) ? $map : [];
    }

    /**
     * Persist failure/backoff map.
     */
    private static function save_failure_map($map) {
        if (empty($map)) {
            delete_option(self::OPTION_FAILURES);
            return;
        }
        update_option(self::OPTION_FAILURES, $map, false);
    }

    /**
     * Remove failure entries that no longer exist in the queue.
     *
     * @param array $queue
     */
    private static function cleanup_failure_map($queue) {
        $map = self::get_failure_map();
        if (empty($map)) {
            return;
        }

        $valid_keys = [];
        foreach ($queue as $item) {
            $valid_keys[] = self::normalize_queue_key($item);
        }
        $valid_keys = array_unique($valid_keys);

        $changed = false;
        foreach (array_keys($map) as $key) {
            if (!in_array($key, $valid_keys, true)) {
                unset($map[$key]);
                $changed = true;
            }
        }

        if ($changed) {
            self::save_failure_map($map);
        }
    }

    /**
     * Determine if a queue key is in backoff; clears stale entries.
     */
    private static function get_backoff_until($queue_key) {
        if ('' === $queue_key) {
            return 0;
        }

        $map = self::get_failure_map();
        if (!isset($map[$queue_key])) {
            return 0;
        }

        $backoff_until = isset($map[$queue_key]['backoff_until']) ? (int) $map[$queue_key]['backoff_until'] : 0;
        if ($backoff_until <= time()) {
            unset($map[$queue_key]);
            self::save_failure_map($map);
            return 0;
        }

        return $backoff_until;
    }

    /**
     * Record a failure and apply exponential-ish backoff (15m steps capped at 6h).
     * @return bool True if item should be dropped due to too many failures.
     */
    private static function mark_failure($queue_key) {
        if ('' === $queue_key) {
            return false;
        }

        $map = self::get_failure_map();
        $count = isset($map[$queue_key]['count']) ? (int) $map[$queue_key]['count'] : 0;
        $count++;

        $delay = max(15 * MINUTE_IN_SECONDS, $count * 15 * MINUTE_IN_SECONDS);
        $delay = min(6 * HOUR_IN_SECONDS, $delay);

        $map[$queue_key] = [
            'count' => $count,
            'backoff_until' => time() + $delay,
            'last_failed' => time(),
        ];

        self::save_failure_map($map);
        return ($count >= self::MAX_FAILURES);
    }

    /**
     * Clear recorded failure/backoff.
     */
    private static function clear_failure($queue_key) {
        if ('' === $queue_key) {
            return;
        }

        $map = self::get_failure_map();
        if (isset($map[$queue_key])) {
            unset($map[$queue_key]);
            self::save_failure_map($map);
        }
    }

    /**
     * Record last run metadata for diagnostics.
     */
    private static function set_last_run($processed, $rate_limited, $last_error) {
        $payload = [
            'ts' => time(),
            'processed' => (int) $processed,
            'rate_limited' => (bool) $rate_limited,
            'last_error' => (string) $last_error,
        ];
        update_option('flickr_justified_cache_warmer_last_run', $payload, false);
    }

    /**
     * Get current pause window (timestamp).
     */
    private static function get_pause_until() {
        $value = get_option(self::OPTION_PAUSE_UNTIL, 0);
        return (int) $value;
    }

    /**
     * Set a pause window until timestamp.
     */
    private static function set_pause_until($timestamp) {
        $timestamp = (int) $timestamp;
        if ($timestamp <= time()) {
            delete_option(self::OPTION_PAUSE_UNTIL);
            return;
        }
        update_option(self::OPTION_PAUSE_UNTIL, $timestamp, false);
    }

    /**
     * Clear pause window.
     */
    private static function clear_pause_until() {
        delete_option(self::OPTION_PAUSE_UNTIL);
    }

    /**
     * Save the queue.
     */
    private static function save_queue($queue) {
        if (empty($queue)) {
            delete_option(self::OPTION_QUEUE);
            delete_option(self::OPTION_FAILURES);
            return;
        }

        update_option(self::OPTION_QUEUE, array_values($queue), false);
    }

    /**
     * Rebuild known URLs by scanning posts.
     */
    public static function rebuild_known_urls() {
        global $wpdb;

        $like = '%' . $wpdb->esc_like('flickr-justified/block') . '%';
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision') AND post_status NOT IN ('trash', 'auto-draft') AND post_content LIKE %s",
                $like
            ),
            ARRAY_A
        );

        $map = [];

        if (!empty($results)) {
            foreach ($results as $row) {
                $post_id = (int) $row['ID'];
                $urls = self::extract_block_urls($row['post_content']);
                if (!empty($urls)) {
                    $map[$post_id] = $urls;
                }
            }
        }

        self::save_known_url_map($map);

        return $map;
    }

    /**
     * Register the WP-CLI command.
     */
    private static function register_cli_command() {
        \WP_CLI::add_command('flickr-justified warm-cache', function($args, $assoc_args) {
            $rebuild = isset($assoc_args['rebuild']);
            $force = isset($assoc_args['force']);
            $max_seconds = isset($assoc_args['max-seconds']) ? (int) $assoc_args['max-seconds'] : null;
            if (null !== $max_seconds && $max_seconds < 1) {
                $max_seconds = null;
            }

            if ($rebuild) {
                $map = self::rebuild_known_urls();
                $count = 0;
                foreach ($map as $urls) {
                    if (is_array($urls)) {
                        $count += count($urls);
                    }
                }
                \WP_CLI::log(sprintf(__('Rebuilt URL list with %d Flickr link(s).', 'flickr-justified-block'), $count));
            }

            $processed = self::process_queue(true, $force, $max_seconds);
            $message = sprintf(__('Warmed cache for %d Flickr request(s).', 'flickr-justified-block'), $processed);
            if (null !== $max_seconds) {
                $message .= ' ' . sprintf(__('Stopped after %d seconds limit.', 'flickr-justified-block'), $max_seconds);
            }
            \WP_CLI::success($message);
        });
    }
}
