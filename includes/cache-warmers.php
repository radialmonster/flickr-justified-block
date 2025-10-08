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
    private const CRON_HOOK = 'flickr_justified_run_cache_warmer';
    private const CRON_SCHEDULE = 'flickr_justified_cache_warm_interval';
    private const FAST_DELAY = 60; // seconds.
    private const SLOW_DELAY = 300; // seconds.

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
     * Register the recurring cron interval based on cache duration.
     */
    public static function register_cron_schedule($schedules) {
        if (!is_array($schedules)) {
            $schedules = [];
        }

        $cache_duration = (int) flickr_justified_get_admin_setting('get_cache_duration', WEEK_IN_SECONDS);
        if ($cache_duration <= 0) {
            $cache_duration = WEEK_IN_SECONDS;
        }

        $interval = (int) max(HOUR_IN_SECONDS, min($cache_duration, DAY_IN_SECONDS));

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
     * @return int Number of URLs processed.
     */
    public static function process_queue($process_all = false) {
        if (!self::is_enabled() && !$process_all) {
            return 0;
        }

        $queue = self::get_queue();

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

        foreach ($queue as $url) {
            if (!$process_all && $attempted >= $batch_size) {
                $remaining[] = $url;
                continue;
            }

            $attempted++;

            if (self::warm_url($url)) {
                $processed++;
                continue;
            }

            $remaining[] = $url;
        }

        self::save_queue($remaining);

        if (!$process_all && !empty($remaining)) {
            self::schedule_next_batch(self::get_delay_interval());
        }

        return $processed;
    }

    /**
     * Warm a single Flickr URL.
     */
    private static function warm_url($url) {
        if (!is_string($url) || '' === trim($url)) {
            return false;
        }

        $url = trim($url);

        $available_sizes = [
            'original', 'large6k', 'large5k', 'largef', 'large4k', 'large3k',
            'large2048', 'large1600', 'large1024', 'large',
            'medium800', 'medium640', 'medium500', 'medium',
            'small400', 'small320', 'small240',
            'thumbnail100', 'thumbnail150s', 'thumbnail75s',
        ];

        if (function_exists('flickr_justified_is_flickr_photo_url') && flickr_justified_is_flickr_photo_url($url)) {
            if (!function_exists('flickr_justified_get_flickr_image_sizes_with_dimensions')) {
                return false;
            }

            $success = false;

            $data = flickr_justified_get_flickr_image_sizes_with_dimensions($url, $available_sizes, true);
            if (!empty($data)) {
                $success = true;
            }

            if (function_exists('flickr_justified_extract_photo_id') && function_exists('flickr_justified_get_photo_stats')) {
                $photo_id = flickr_justified_extract_photo_id($url);
                if (!empty($photo_id)) {
                    $stats = flickr_justified_get_photo_stats($photo_id);
                    if (!empty($stats)) {
                        $success = true;
                    }
                }
            }

            return $success;
        }

        if (!function_exists('flickr_justified_parse_set_url')) {
            return false;
        }

        $set_info = flickr_justified_parse_set_url($url);
        if (!$set_info || empty($set_info['user_id']) || empty($set_info['photoset_id'])) {
            return false;
        }

        if (!function_exists('flickr_justified_get_photoset_photos_paginated')) {
            return false;
        }

        if (!function_exists('flickr_justified_get_flickr_image_sizes_with_dimensions')) {
            return false;
        }

        $per_page = 50;
        $result = flickr_justified_get_photoset_photos_paginated($set_info['user_id'], $set_info['photoset_id'], 1, $per_page);

        if (empty($result) || empty($result['photos']) || !is_array($result['photos'])) {
            return false;
        }

        $photos = array_slice(array_values($result['photos']), 0, $per_page);
        $stats_available = function_exists('flickr_justified_extract_photo_id') && function_exists('flickr_justified_get_photo_stats');
        $success = false;

        foreach ($photos as $photo_url) {
            $photo_url = trim((string) $photo_url);
            if ('' === $photo_url) {
                continue;
            }

            $data = flickr_justified_get_flickr_image_sizes_with_dimensions($photo_url, $available_sizes, true);
            if (!empty($data)) {
                $success = true;
            }

            if ($stats_available) {
                $photo_id = flickr_justified_extract_photo_id($photo_url);
                if (!empty($photo_id)) {
                    $stats = flickr_justified_get_photo_stats($photo_id);
                    if (!empty($stats)) {
                        $success = true;
                    }
                }
            }
        }

        return $success;
    }

    /**
     * Schedule the next batch run.
     */
    public static function schedule_next_batch($delay = null) {
        if (!$delay && 0 !== $delay) {
            $delay = self::get_delay_interval();
        }

        $timestamp = time() + max(0, (int) $delay);

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
     * @param bool $force When true, overwrite the queue even if it already has entries.
     * @return array Resulting queue.
     */
    private static function prime_queue_from_known_urls($force = false) {
        $current_queue = self::get_queue();

        if (!$force && !empty($current_queue)) {
            return $current_queue;
        }

        $urls = self::get_all_known_urls();
        self::save_queue($urls);

        return $urls;
    }

    /**
     * Retrieve the current queue.
     */
    private static function get_queue() {
        $queue = get_option(self::OPTION_QUEUE, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        $queue = array_filter(array_map('trim', $queue));
        $queue = array_unique($queue);

        return array_values($queue);
    }

    /**
     * Save the queue.
     */
    private static function save_queue($queue) {
        if (empty($queue)) {
            delete_option(self::OPTION_QUEUE);
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

            $processed = self::process_queue(true);
            if ($processed > 0) {
                \WP_CLI::success(sprintf(__('Warmed cache for %d Flickr request(s).', 'flickr-justified-block'), $processed));
            } else {
                \WP_CLI::success(__('No Flickr URLs required warming.', 'flickr-justified-block'));
            }
        });
    }
}
