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
        add_action('flickr_justified_prune_jobs', [__CLASS__, 'prune_completed_jobs']);

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

        if (!wp_next_scheduled('flickr_justified_prune_jobs')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'flickr_justified_prune_jobs');
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

        $timestamp = wp_next_scheduled('flickr_justified_prune_jobs');
        while (false !== $timestamp) {
            wp_unschedule_event($timestamp, 'flickr_justified_prune_jobs');
            $timestamp = wp_next_scheduled('flickr_justified_prune_jobs');
        }
    }

    /**
     * Activation hook callback.
     */
    public static function handle_activation() {
        self::rebuild_known_urls();
        self::prime_queue_from_known_urls(true);
        // Drop legacy photo jobs and keep only bulk set/photostream jobs.
        self::reset_to_bulk_queue();
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

        $default_calls = 20;
        if (function_exists('flickr_justified_get_admin_setting')) {
            $default_calls = (int) flickr_justified_get_admin_setting('get_cache_warmer_api_calls_per_run', 20);
        }
        $max_api_calls = $process_all ? PHP_INT_MAX : (int) apply_filters('flickr_justified_cache_warmer_max_api_calls', $default_calls);
            if ($max_api_calls < 1) {
                $max_api_calls = 1;
            }

            $processed = 0;
            $attempted = 0;
            $remaining = [];
            $rate_limited = false;
            $last_error = '';

            foreach ($queue as $queue_item) {
                if (null !== $max_seconds && (microtime(true) - $start_time) >= $max_seconds) {
                    $remaining[] = $queue_item;
                    continue;
                }

                if (!$process_all && $processed >= $max_api_calls) {
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

                $job_type = isset($queue_item['job_type']) ? $queue_item['job_type'] : (function_exists('flickr_justified_is_flickr_photo_url') && flickr_justified_is_flickr_photo_url($url) ? 'photo' : 'set_page');
                $queue_key = self::normalize_queue_key($queue_item);
                $attempted++;

                $result = self::warm_url($url, $page, $job_type);

                if ($result === 'rate_limited') {
                    // Rate limited - stop processing and keep this URL for retry
                    $rate_limited = true;
                    $remaining[] = $queue_item;
                    self::clear_failure($queue_key); // legacy map cleanup
                    self::set_pause_until(time() + HOUR_IN_SECONDS);
                    self::mark_job_backoff($job_type, $url, $page, HOUR_IN_SECONDS, 'rate_limited');

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
                        self::clear_failure($queue_key); // legacy map cleanup
                        self::mark_job_done($job_type, $url, $page);

                        // If we timed out on this page, retry it before moving forward.
                        if (!empty($result['needs_retry_page'])) {
                            array_unshift($remaining, [
                                'url' => $url,
                                'page' => $result['current_page'],
                                'job_type' => $job_type,
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
                            $next_item = [
                                'url' => $url,
                                'page' => $next_page,
                                'job_type' => $job_type,
                            ];
                            if (isset($queue_item['user_id'])) {
                                $next_item['user_id'] = $queue_item['user_id'];
                            }
                            array_unshift($remaining, $next_item);

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
                        self::mark_job_backoff($job_type, $url, $page, 300, 'album_fail');
                        $last_error = 'album_fail';
                    }
                } elseif ($result) {
                    // Simple success (photo URL)
                    $processed++;
                    self::clear_failure($queue_key); // legacy map cleanup
                    self::mark_job_done($job_type, $url, $page);
                    continue;
                } else {
                    // Failed for other reasons - keep in queue for retry
                    // Legacy failure map cleanup; real backoff handled by job table.
                    self::mark_failure($queue_key);
                    $remaining[] = $queue_item;
                    self::mark_job_backoff($job_type, $url, $page, 300, 'photo_fail');
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
    public static function warm_url($url, $page = 1, $job_type = 'set_page') {
        if (!is_string($url) || '' === trim($url)) {
            return false;
        }

        $url = trim($url);
        $page = max(1, (int) $page);
        $start_time = microtime(true);
        $default_seconds = (int) flickr_justified_get_admin_setting('get_cache_warmer_max_seconds', 20);
        $max_duration = (int) apply_filters('flickr_justified_cache_warmer_max_seconds', $default_seconds);
        $max_duration = max(5, $max_duration);
        $sleep_seconds = 0.0; // No per-photo sleeping in bulk mode

        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = 'Flickr warm_url processing [' . $job_type . ']: ' . $url;
            if ($page > 1) {
                $log_message .= ' (page ' . $page . ')';
            }
            error_log($log_message);
        }

        // Photostream page job (bulk)
        if ('photostream_page' === $job_type) {
            // Expect url format photostream:{user} and/or payload user_id
            $user_id = '';
            if (strpos($url, 'photostream:') === 0) {
                $user_id = substr($url, strlen('photostream:'));
            }
            if (empty($user_id) && preg_match('#flickr\.com/photos/([^/]+)/#', $url, $m)) {
                $user_id = $m[1];
            }
            if (empty($user_id)) {
                return false;
            }

            $result = FlickrJustifiedCache::get_photostream_photos($user_id, $page, 500);
            if (is_array($result) && isset($result['rate_limited']) && $result['rate_limited']) {
                return 'rate_limited';
            }
            if (empty($result['photos'])) {
                return false;
            }

            return [
                'success' => true,
                'has_more_pages' => !empty($result['has_more']),
                'needs_retry_page' => false,
                'current_page' => isset($result['page']) ? (int) $result['page'] : $page,
                'total_pages' => isset($result['pages']) ? (int) $result['pages'] : 1,
                'user_id' => $user_id,
            ];
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

                // Always warm photo info to ensure DB persistence and context updates
                // Force refresh for queued photo jobs to ensure fresh data and DB persistence
                $info = FlickrJustifiedCache::get_photo_info($photo_id, true);
                if (is_array($info) && !empty($info)) {
                    $success = true;
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
                // No per-photo sleep in bulk mode.
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
            // Override with a high page size so we warm hundreds of photos with a single Flickr call.
            $per_page = (int) apply_filters('flickr_justified_cache_warmer_album_page_size', 500);
            if ($per_page < 1) {
                $per_page = 1;
            } elseif ($per_page > 500) {
                $per_page = 500;
            }

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

            // The album call already cached each photo's sizes + views; avoid per-photo API thrash.
            $success = true;

            // Persist a views index so views_desc sorting is instant.
            $photo_views_map = isset($result['photo_views']) && is_array($result['photo_views']) ? $result['photo_views'] : [];
            $photo_urls_map = isset($result['photo_urls_map']) && is_array($result['photo_urls_map']) ? $result['photo_urls_map'] : [];
            if (!empty($photo_views_map) && !empty($photo_urls_map)) {
                $resolved_user_id = FlickrJustifiedCache::resolve_user_id($set_info['user_id']);
                if ($resolved_user_id) {
                    FlickrJustifiedCache::persist_set_views_index(
                        $resolved_user_id,
                        $set_info['photoset_id'],
                        $photo_views_map,
                        $photo_urls_map,
                        !empty($result['has_more']),
                        $total_photos
                    );
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
            return $current_queue;
        }

        $urls = self::get_all_known_urls();
        $photostream_users = [];

        if (!$force) {
            // Simple case: queue is empty, just use the new URLs
            $queue = $urls;
            // Collect users for photostream jobs.
            foreach ($urls as $u) {
                if (preg_match('#flickr\.com/photos/([^/]+)/#', $u, $m)) {
                    $photostream_users[] = $m[1];
                }
            }
            $queue = self::append_photostream_jobs($queue, $photostream_users);
            self::save_queue($queue);
            return $queue;
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

            if (preg_match('#flickr\.com/photos/([^/]+)/#', $url, $m)) {
                $photostream_users[] = $m[1];
            }
        }

        $merged_queue = self::append_photostream_jobs($merged_queue, $photostream_users);

        self::save_queue($merged_queue);
        return $merged_queue;
    }

    /**
     * Retrieve the current queue.
     * Queue items can be either strings (legacy format) or arrays with 'url' and 'page' keys.
     */
    private static function get_queue() {
        global $wpdb;
        $table = $wpdb->prefix . 'fjb_jobs';
        self::ensure_jobs_table();

        $rows = $wpdb->get_results(
            "SELECT job_key, job_type, payload_json, priority FROM {$table}
             WHERE status = 'pending' AND (not_before IS NULL OR not_before <= NOW())
             ORDER BY priority DESC, created_at ASC
             LIMIT 500"
        );

        $queue = [];
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $payload = json_decode($row->payload_json, true);
                if (!is_array($payload)) {
                    continue;
                }
                $url = isset($payload['url']) ? trim((string) $payload['url']) : '';
                $page = isset($payload['page']) ? (int) $payload['page'] : 1;
                $user_id = isset($payload['user_id']) ? $payload['user_id'] : '';
                if ('' === $url) {
                    continue;
                }
                $queue[] = [
                    'url' => $url,
                    'page' => $page,
                    'job_type' => $row->job_type,
                    'job_key' => $row->job_key,
                    'user_id' => $user_id,
                    'priority' => isset($row->priority) ? (int) $row->priority : 0,
                ];
            }
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
     * Enqueue a set_page job for a given set/page at the provided priority.
     */
    private static function enqueue_set_page($photoset_id, $page = 1, $priority = 10) {
        $photoset_id = trim((string) $photoset_id);
        $page = max(1, (int) $page);
        if ('' === $photoset_id) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fjb_jobs';
        self::ensure_jobs_table();

        $user = flickr_justified_get_admin_setting('api_user', 'radialmonster');
        $user = rawurldecode((string) $user);
        $url = 'https://flickr.com/photos/' . $user . '/albums/' . $photoset_id;
        $payload = [
            'url' => $url,
            'page' => $page,
        ];
        $job_key = self::build_job_key('set_page', $url, $page);

        $wpdb->replace(
            $table,
            [
                'job_key' => $job_key,
                'job_type' => 'set_page',
                'payload_json' => wp_json_encode($payload),
                'priority' => $priority,
                'not_before' => null,
                'attempts' => 0,
                'last_error' => null,
                'status' => 'pending',
            ]
        );
    }

    /**
     * Normalize queue item to a unique key for tracking failures/backoff.
     */
    private static function normalize_queue_key($item) {
        if (is_array($item)) {
            $url = isset($item['url']) ? trim((string) $item['url']) : '';
            $page = isset($item['page']) ? (int) $item['page'] : 1;
            $job_type = isset($item['job_type']) ? $item['job_type'] : (function_exists('flickr_justified_is_flickr_photo_url') && flickr_justified_is_flickr_photo_url($url) ? 'photo' : 'set_page');
            return $job_type . ':' . $url . '|page:' . $page;
        }

        $url = trim((string) $item);
        $job_type = (function_exists('flickr_justified_is_flickr_photo_url') && flickr_justified_is_flickr_photo_url($url)) ? 'photo' : 'set_page';
        return $job_type . ':' . $url . '|page:1';
    }

    // Legacy failure map is deprecated; keep no-ops for backward compatibility.
    private static function get_failure_map() { return []; }
    private static function save_failure_map($map) { }
    private static function cleanup_failure_map($queue) { }
    private static function get_backoff_until($queue_key) { return 0; }
    private static function mark_failure($queue_key) { return false; }
    private static function clear_failure($queue_key) { }

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
        global $wpdb;
        $table = $wpdb->prefix . 'fjb_jobs';
        self::ensure_jobs_table();

        // If queue is empty, clear pending jobs.
        if (empty($queue)) {
            $wpdb->query("DELETE FROM {$table} WHERE status = 'pending'");
            return;
        }

        // Wipe pending jobs and rebuild from queue snapshot.
        $wpdb->query("DELETE FROM {$table} WHERE status = 'pending'");

        foreach ($queue as $item) {
            $url = '';
            $page = 1;
            $user_id = '';
            if (is_array($item)) {
                $url = isset($item['url']) ? trim((string) $item['url']) : '';
                $page = isset($item['page']) ? (int) $item['page'] : 1;
                $user_id = isset($item['user_id']) ? $item['user_id'] : '';
            } else {
                $url = trim((string) $item);
            }
            if ('' === $url) {
                continue;
            }

            $payload = [
                'url' => $url,
                'page' => $page,
            ];
            if ('' !== $user_id) {
                $payload['user_id'] = $user_id;
            }
            $job_type = 'set_page';
            if (is_array($item) && isset($item['job_type']) && '' !== $item['job_type']) {
                $job_type = $item['job_type'];
            } elseif (function_exists('flickr_justified_is_flickr_photo_url') && flickr_justified_is_flickr_photo_url($url)) {
                $job_type = 'photo';
            }

            $priority = 0;
            if ($job_type === 'photo') {
                $priority = 20; // Higher priority for individual photo refreshes
            }
            if ('photostream_page' === $job_type) {
                $priority = -5;
            }
            if ('set_page' === $job_type) {
                $priority = 10;
            }
            if (is_array($item) && (isset($item['priority']) || array_key_exists('priority', $item))) {
                $priority = (int) $item['priority'];
            }

            $job_key = $job_type . ':' . md5($url . '|' . $page);
            $wpdb->replace(
                $table,
                [
                    'job_key' => $job_key,
                    'job_type' => $job_type,
                    'payload_json' => wp_json_encode($payload),
                    'priority' => $priority,
                    'status' => 'pending',
                    'not_before' => null,
                    'attempts' => 0,
                    'last_error' => null,
                ]
            );
        }

        // Drop legacy option queue entirely.
        // (No-op now; legacy option removed.)
    }

    /**
     * Reset the queue to bulk mode: drop all photo jobs and rebuild from content.
     */
    public static function reset_to_bulk_queue() {
        global $wpdb;
        $table = $wpdb->prefix . 'fjb_jobs';
        self::ensure_jobs_table();

        // Drop all existing jobs to remove legacy photo jobs.
        if ($wpdb->query("DELETE FROM {$table}") === false) {
            throw new RuntimeException('Failed to clear jobs table');
        }

        // Clear any legacy option queue remnants.
        delete_option('flickr_justified_cache_warmer_queue');

        // Rebuild from content (set_page + photostream as configured).
        self::rebuild_known_urls();
        self::prime_queue_from_known_urls(true);
    }

    /**
     * Rebuild queue from content without wiping existing jobs.
     *
     * @param bool $force Whether to merge/refresh paginated entries (true keeps existing page>1 items).
     */
    public static function rebuild_queue_from_content($force = true) {
        self::rebuild_known_urls();
        self::prime_queue_from_known_urls($force);
    }

    /**
     * Requeue any photos missing core metadata (server, secret, or views_checked_at).
     * This backfills by enqueueing their albums and photostream pages at normal priority.
     */
    public static function requeue_missing_metadata() {
        global $wpdb;
        $meta_table = $wpdb->prefix . 'fjb_photo_meta';
        $membership_table = $wpdb->prefix . 'fjb_photo_membership';

        // First, heal bad timestamps for rows that already have some data.
        $wpdb->query(
            "UPDATE {$meta_table}
             SET updated_at = NOW()
             WHERE (updated_at IS NULL OR updated_at = '0000-00-00 00:00:00')
               AND (server <> '' OR secret <> '' OR views_checked_at IS NOT NULL)"
        );

        // Find photos missing critical fields.
        $photos = $wpdb->get_results(
            "SELECT photo_id FROM {$meta_table}
             WHERE server IS NULL OR server = ''
                OR secret IS NULL OR secret = ''
                OR views_checked_at IS NULL
                OR updated_at IS NULL OR updated_at = '0000-00-00 00:00:00'
             LIMIT 1000",
            ARRAY_A
        );

        if (empty($photos)) {
            return 0;
        }

        $photo_ids = wp_list_pluck($photos, 'photo_id');

        // Map to albums via membership table.
        $placeholders = implode(',', array_fill(0, count($photo_ids), '%s'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT photoset_id FROM {$membership_table} WHERE photo_id IN ({$placeholders})",
                $photo_ids
            ),
            ARRAY_A
        );

        $queued = 0;
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $photoset_id = trim((string) $row['photoset_id']);
                if ($photoset_id === '') {
                    continue;
                }
                self::enqueue_set_page($photoset_id, 1, 10);
                $queued++;
            }
        }

        // Also enqueue photostream page 1 for primary users referenced in known URLs.
        $known_urls = self::get_all_known_urls();
        $users = [];
        foreach ($known_urls as $url) {
            if (preg_match('#flickr\.com/photos/([^/]+)/#', $url, $m)) {
                $users[] = $m[1];
            }
        }
        $users = array_unique($users);
        if (!empty($users)) {
            $queue = [];
            $queue = self::append_photostream_jobs($queue, $users);
            self::save_queue(array_merge(self::get_queue(), $queue));
            $queued += count($queue);
        }

        return $queued;
    }

    /**
     * Ensure the jobs table exists for queue storage.
     */
    private static function ensure_jobs_table() {
        global $wpdb;
        static $created = false;
        if ($created) {
            return;
        }

        $table = $wpdb->prefix . 'fjb_jobs';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table} (
            job_key VARCHAR(191) NOT NULL,
            job_type VARCHAR(32) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            priority INT NOT NULL DEFAULT 0,
            not_before DATETIME DEFAULT NULL,
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (job_key),
            KEY idx_status_priority (status, priority, created_at),
            KEY idx_not_before (not_before)
        ) {$charset_collate};";
        dbDelta($sql);

        $created = true;
    }

    /**
     * Append photostream jobs (page 1) for provided users if enabled.
     *
     * @param array $queue
     * @param array $user_ids
     * @return array
     */
    private static function append_photostream_jobs($queue, $user_ids) {
        $enable_photostream = (bool) apply_filters(
            'flickr_justified_cache_warmer_enable_photostream',
            (bool) flickr_justified_get_admin_setting('is_cache_warmer_photostream_enabled', true)
        );
        if (!$enable_photostream || empty($user_ids)) {
            return $queue;
        }

        $existing = [];
        foreach ($queue as $item) {
            if (is_array($item) && isset($item['job_type'], $item['user_id']) && $item['job_type'] === 'photostream_page') {
                $existing[$item['user_id']] = true;
            } elseif (is_array($item) && isset($item['url']) && strpos((string) $item['url'], 'photostream:') === 0) {
                $existing[(string) $item['url']] = true;
            }
        }

        foreach (array_unique($user_ids) as $user) {
            if (isset($existing[$user]) || isset($existing['photostream:' . $user])) {
                continue;
            }
            $queue[] = [
                'url' => 'photostream:' . $user,
                'page' => 1,
                'job_type' => 'photostream_page',
                'user_id' => $user,
            ];
        }

        return $queue;
    }

    /**
     * Enqueue a high-priority set refresh job (hot path when views are stale).
     *
     * @param string $photoset_id
     * @param string $resolved_user_id
     * @param bool $reset_existing_jobs If true, also reset any existing "done" jobs for this album
     */
    public static function enqueue_hot_set($photoset_id, $resolved_user_id, $reset_existing_jobs = false) {
        $photoset_id = trim((string) $photoset_id);
        $resolved_user_id = trim((string) $resolved_user_id);
        if ('' === $photoset_id || '' === $resolved_user_id) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fjb_jobs';
        self::ensure_jobs_table();

        $url = 'https://flickr.com/photos/' . rawurlencode($resolved_user_id) . '/albums/' . $photoset_id;
        $payload = [
            'url' => $url,
            'page' => 1,
        ];
        $job_type = 'set_page';
        $job_key = self::build_job_key($job_type, $url, 1);

        // If requested, reset any existing "done" jobs for this album back to "pending"
        // This handles the case where cache was flushed but jobs stayed "done"
        if ($reset_existing_jobs) {
            $like_pattern = '%' . $wpdb->esc_like($photoset_id) . '%';
            $reset_count = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET status = 'pending', attempts = 0, last_error = NULL WHERE payload_json LIKE %s AND status = 'done'",
                    $like_pattern
                )
            );

            if ($reset_count > 0 && defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'Flickr Justified: Reset %d stale "done" job(s) for album %s (cache was empty)',
                    $reset_count,
                    $photoset_id
                ));
            }
        }

        $wpdb->replace(
            $table,
            [
                'job_key' => $job_key,
                'job_type' => $job_type,
                'payload_json' => wp_json_encode($payload),
                'priority' => 50, // hot priority
                'status' => 'pending',
                'not_before' => null,
                'attempts' => 0,
                'last_error' => null,
            ]
        );
    }

    /**
     * Helper to build a deterministic job key.
     */
    private static function build_job_key($job_type, $url, $page) {
        return $job_type . ':' . md5($url . '|' . (int) $page);
    }

    /**
     * Mark a job as done.
     */
    private static function mark_job_done($job_type, $url, $page) {
        global $wpdb;
        $table = $wpdb->prefix . 'fjb_jobs';
        self::ensure_jobs_table();
        $job_key = self::build_job_key($job_type, $url, $page);
        $wpdb->update(
            $table,
            ['status' => 'done', 'not_before' => null, 'last_error' => null, 'updated_at' => current_time('mysql')],
            ['job_key' => $job_key],
            ['%s', '%s', '%s', '%s'],
            ['%s']
        );
    }

    /**
     * Back off a job with a delay and error message.
     */
    private static function mark_job_backoff($job_type, $url, $page, $delay_seconds, $error) {
        global $wpdb;
        $table = $wpdb->prefix . 'fjb_jobs';
        self::ensure_jobs_table();
        $job_key = self::build_job_key($job_type, $url, $page);
        $not_before = date('Y-m-d H:i:s', time() + max(0, (int) $delay_seconds));
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET not_before = %s,
                     attempts = attempts + 1,
                     last_error = %s,
                     status = 'pending',
                     updated_at = %s
                 WHERE job_key = %s",
                $not_before,
                $error,
                current_time('mysql'),
                $job_key
            )
        );
    }

    /**
     * Prune completed jobs older than a retention window.
     */
    public static function prune_completed_jobs() {
        global $wpdb;
        $table = $wpdb->prefix . 'fjb_jobs';
        self::ensure_jobs_table();

        $retain_days = (int) apply_filters('flickr_justified_jobs_retain_days', 7);
        if ($retain_days < 1) {
            $retain_days = 1;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($retain_days * DAY_IN_SECONDS));
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE status = 'done' AND updated_at < %s",
                $cutoff
            )
        );

        // Clear very old partial set snapshots to avoid stale data.
        $partial_ttl_days = (int) apply_filters('flickr_justified_partial_cache_retain_days', 14);
        if ($partial_ttl_days < 1) {
            $partial_ttl_days = 1;
        }
        $partial_cutoff = time() - ($partial_ttl_days * DAY_IN_SECONDS);
        // Partial snapshots are stored as transients; rely on expiration + optional filter hook.
        do_action('flickr_justified_cleanup_partials', $partial_cutoff);
        if (class_exists('FlickrJustifiedCache')) {
            FlickrJustifiedCache::cleanup_partials($partial_cutoff);
        }
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
