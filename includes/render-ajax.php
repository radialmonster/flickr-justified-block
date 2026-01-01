<?php
/**
 * AJAX handlers for Flickr Justified Block
 *
 * Functions for async loading and AJAX endpoint handling.
 *
 * @package FlickrJustifiedBlock
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// ASYNC LOADING DETECTION
// ============================================================================

/**
 * Check if block should use async loading to prevent timeouts.
 * Returns true if URLs contain large uncached albums.
 *
 * @param string $urls Raw URL string from block
 * @return bool True if should use async loading
 */
function flickr_justified_should_use_async_loading($urls) {
    // Allow forcing synchronous render (e.g., when already in async context)
    if (apply_filters('flickr_justified_force_sync_render', false)) {
        return false;
    }

    if (empty($urls) || !is_string($urls)) {
        return false;
    }

    // Parse URLs
    $url_lines = array_filter(array_map('trim', preg_split('/\R/u', $urls)));
    $final_urls = [];
    foreach ($url_lines as $line) {
        if (preg_match_all('/https?:\/\/[^\s]+/i', $line, $matches)) {
            foreach ($matches[0] as $url) {
                $final_urls[] = trim($url);
            }
        } else if (!empty($line)) {
            $final_urls[] = $line;
        }
    }

    // Check if any URLs are albums or uncached photos
    foreach ($final_urls as $url) {
        $set_info = flickr_justified_parse_set_url($url);
        if ($set_info && !empty($set_info['photoset_id'])) {
            // Resolve user ID first (checks cache, won't make API call if cached)
            $resolved_user_id = FlickrJustifiedCache::resolve_user_id($set_info['user_id']);
            if (!$resolved_user_id) {
                // Can't resolve user - use async to handle gracefully
                return true;
            }

            // Quick check: is this album cached? Check BOTH full and paginated cache
            $cache_key_full = ['set_full', md5($resolved_user_id . '_' . $set_info['photoset_id'])];
            $cache_key_page = ['set_page_v2', md5($resolved_user_id . '_' . $set_info['photoset_id'] . '_1_50')];

            $cached_full = FlickrJustifiedCache::get($cache_key_full);
            $cached_page = FlickrJustifiedCache::get($cache_key_page);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    'Async check for album %s: user_id=%s, cached_full=%s, cached_page=%s',
                    $set_info['photoset_id'],
                    $resolved_user_id,
                    empty($cached_full) ? 'NO' : 'YES',
                    empty($cached_page) ? 'NO' : 'YES'
                ));
            }

            // Use async loading if album isn't FULLY cached
            // Even if first page is cached, we don't want to timeout fetching the full album
            if (empty($cached_full)) {
                // Full album not cached - use async loading to prevent timeout
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Async check: Album not fully cached, forcing async loading');
                }
                return true;
            }

            // IMPORTANT: Even with cached data, large albums take too long to render HTML
            // Rendering 1500+ photos synchronously can exceed PHP timeout (30-60s)
            // Force async for albums with > 100 photos to keep page load fast
            if (!empty($cached_full) && isset($cached_full['photos']) && is_array($cached_full['photos'])) {
                $photo_count = count($cached_full['photos']);
                if ($photo_count > 100) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log(sprintf('Async check: Album has %d photos (> 100), forcing async for performance', $photo_count));
                    }
                    return true;
                }
            }
        } elseif (flickr_justified_is_flickr_photo_url($url)) {
            // Check if individual photo is cached
            $photo_id = flickr_justified_extract_photo_id($url);
            if ($photo_id) {
                // Check if photo sizes are cached
                $available_sizes = flickr_justified_get_available_flickr_sizes();
                $size_key_hash = md5(implode(',', $available_sizes));
                $cache_key = ['dims', $photo_id, $size_key_hash];
                $cached_photo = FlickrJustifiedCache::get($cache_key);

                if (empty($cached_photo) || (isset($cached_photo['not_found']) && $cached_photo['not_found'])) {
                    // Photo not cached - use async loading to prevent timeout/rate limit
                    return true;
                }
            }
        }
    }

    return false;
}

// ============================================================================
// AJAX HANDLERS
// ============================================================================

/**
 * AJAX handler for async block loading
 *
 * Renders the block content asynchronously to prevent page load timeouts.
 */
function flickr_justified_ajax_load_async() {
    // Verify nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'flickr_justified_async_load')) {
        wp_send_json_error('Security check failed', 403);
    }

    if (!isset($_POST['attributes'])) {
        wp_send_json_error('No attributes provided');
    }

    $attributes_json = stripslashes($_POST['attributes']);
    $attributes = json_decode($attributes_json, true);

    if (!is_array($attributes)) {
        wp_send_json_error('Invalid attributes');
    }

    // Store post ID globally for error logging context
    if (isset($_POST['post_id']) && !empty($_POST['post_id'])) {
        $GLOBALS['flickr_justified_current_post_id'] = absint($_POST['post_id']);
    }

    // Temporarily disable async loading to render normally
    // (we're already in async context, no need to recurse)
    add_filter('flickr_justified_force_sync_render', '__return_true');

    $html = flickr_justified_render_block($attributes);

    remove_filter('flickr_justified_force_sync_render', '__return_true');

    // Clean up global
    unset($GLOBALS['flickr_justified_current_post_id']);

    wp_send_json_success(['html' => $html]);
}
add_action('wp_ajax_flickr_justified_load_async', 'flickr_justified_ajax_load_async');
add_action('wp_ajax_nopriv_flickr_justified_load_async', 'flickr_justified_ajax_load_async');
