<?php
/**
 * Render callback for Flickr Justified Block
 *
 * Main orchestration file that coordinates photo fetching and HTML generation.
 * Helper functions are split into focused files for maintainability.
 *
 * @package FlickrJustifiedBlock
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load helper modules
require_once __DIR__ . '/render-helpers.php';      // Utility functions (URL parsing, cache wrappers, etc.)
require_once __DIR__ . '/render-photo-fetcher.php'; // Photo fetching from Flickr API/albums
require_once __DIR__ . '/render-html.php';          // HTML generation for galleries
require_once __DIR__ . '/render-ajax.php';          // AJAX handlers and async loading


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

    // Check if we should use async loading (for large uncached albums to prevent timeouts)
    $use_async_loading = flickr_justified_should_use_async_loading($urls);
    $use_async_loading = apply_filters('flickr_justified_enable_async_loader', $use_async_loading, $attributes, $urls);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        $debug_max_photos = isset($attributes['maxPhotos']) ? (int) $attributes['maxPhotos'] : 0;
        $debug_sort_order = isset($attributes['sortOrder']) ? $attributes['sortOrder'] : 'input';
        error_log(sprintf('Flickr render block: URLs=%s, use_async=%s, maxPhotos=%d, sortOrder=%s',
            substr($urls, 0, 100),
            $use_async_loading ? 'YES' : 'NO',
            $debug_max_photos,
            $debug_sort_order
        ));
    }

    if ($use_async_loading) {
        // Return loading placeholder that will load via AJAX (handled by external script)
        $placeholder_id = 'flickr-justified-async-' . uniqid();
        $target_gallery_id = 'flickr-justified-' . uniqid();

        // Store target gallery ID in attributes so rendered gallery uses same ID
        $attributes['_target_gallery_id'] = $target_gallery_id;
        $attributes_json = wp_json_encode($attributes);
        $attributes_b64 = base64_encode($attributes_json);
        $placeholder_html = sprintf(
            '<div id="%s" class="flickr-justified-loading" data-attributes-b64="%s" data-target-id="%s" role="status" aria-live="polite" aria-busy="true" aria-label="%s">
                <div class="flickr-loading-skeleton">
                    <span class="flickr-skel-row"></span>
                    <span class="flickr-skel-row"></span>
                    <span class="flickr-skel-row"></span>
                </div>
                <p class="flickr-loading-text">%s</p>
                <button type="button" class="flickr-loading-retry-btn" style="display:none;">%s</button>
            </div>',
            esc_attr($placeholder_id),
            esc_attr($attributes_b64),
            esc_attr($target_gallery_id),
            esc_attr__('Loading Flickr gallery', 'flickr-justified-block'),
            esc_html__('Loading gallery...', 'flickr-justified-block'),
            esc_html__('Retry', 'flickr-justified-block')
        );

        return $placeholder_html;
    }

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

    // Split URLs by lines and clean them, then handle multiple URLs on same line.
    $url_lines = array_filter(array_map('trim', preg_split('/\R/u', $urls)));

    // Further split any lines that contain multiple URLs (common when copy-pasting).
    $final_urls = [];
    foreach ($url_lines as $line) {
        if (preg_match_all('/https?:\/\/[^\s]+/i', $line, $matches)) {
            foreach ($matches[0] as $url) {
                $final_urls[] = trim($url);
            }
        } elseif (!empty($line)) {
            $final_urls[] = $line;
        }
    }
    // Normalize/dedupe URLs
    $url_lines = array_values(array_unique(array_filter($final_urls)));

    if (empty($url_lines)) {
        return '';
    }

    $max_photos = isset($attributes['maxPhotos']) ? max(0, (int) $attributes['maxPhotos']) : 0;
    $sort_order = isset($attributes['sortOrder']) ? $attributes['sortOrder'] : 'input';
    if (!in_array($sort_order, ['input', 'views_desc'], true)) {
        $sort_order = 'input';
    }

    $needs_stats = ('views_desc' === $sort_order);

    // For views_desc we rely on cached stats only; do not pull live stats during render.
    // CRITICAL: For views_desc sorting, we must process ALL photos first, then sort, then limit.
    // Otherwise we'd only sort the first N photos instead of finding the true top N by views.
    $remaining_limit = ('views_desc' === $sort_order) ? null : (($max_photos > 0) ? $max_photos : null);
    $photo_items = [];
    $set_metadata = [];
    $position_counter = 0;
    $rate_limited = false; // Track if we hit rate limiting
    $used_fallback_fetch = false; // Track if we used fallback fetch for views_desc

    foreach ($url_lines as $url) {
        // Stop processing if we hit rate limiting
        if ($rate_limited) {
            break;
        }

        if (null !== $remaining_limit && $remaining_limit <= 0) {
            break;
        }

        $set_info = flickr_justified_parse_set_url($url);
        if ($set_info) {
            if ('views_desc' === $sort_order) {
                // Use optimized query: get top N photos sorted by views from cache
                $limit_for_query = $max_photos > 0 ? $max_photos : 50; // Default to 50 if no limit
                $top_photos = FlickrJustifiedCache::get_top_viewed_photos_from_set(
                    $set_info['user_id'],
                    $set_info['photoset_id'],
                    $limit_for_query
                );

                // FALLBACK: If cache is empty, fetch first page to show something to the user
                // and trigger background cache warming for next time
                if (empty($top_photos)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log(sprintf(
                            'Flickr Justified: views_desc cache empty for album %s/%s, falling back to first page fetch',
                            $set_info['user_id'],
                            $set_info['photoset_id']
                        ));
                    }

                    // Fetch first page of photos (up to 50)
                    $per_page = max(1, min(50, $limit_for_query));
                    $set_result = flickr_justified_get_photoset_photos_paginated($set_info['user_id'], $set_info['photoset_id'], 1, $per_page);

                    // Trigger background cache warming to fetch all photos + stats for next time
                    // IMPORTANT: Pass true for $reset_existing_jobs to handle cache flush scenarios
                    // where cache is empty but jobs are marked "done"
                    if (class_exists('FlickrJustifiedCacheWarmer') && class_exists('FlickrJustifiedCache')) {
                        // Resolve user_id to numeric ID before enqueueing
                        $resolved_user_id = FlickrJustifiedCache::resolve_user_id($set_info['user_id']);
                        if ($resolved_user_id) {
                            FlickrJustifiedCacheWarmer::enqueue_hot_set(
                                $set_info['photoset_id'],
                                $resolved_user_id,
                                true // Reset any stale "done" jobs
                            );

                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log(sprintf(
                                    'Flickr Justified: Triggered background warming for album %s/%s (resolved to %s) with job reset',
                                    $set_info['user_id'],
                                    $set_info['photoset_id'],
                                    $resolved_user_id
                                ));
                            }
                        }
                    }

                    // Add marker to indicate this was a fallback fetch (partial/unsorted data)
                    if (!isset($set_result['_fallback_fetch'])) {
                        $set_result['_fallback_fetch'] = true;
                    }
                    $used_fallback_fetch = true;
                } else {
                    $set_result = ['photos' => $top_photos];
                }
            } elseif (null !== $remaining_limit) {
                // For input order with limit: use paginated fetching
                $per_page = max(1, min(50, $remaining_limit));
                $set_result = flickr_justified_get_photoset_photos_paginated($set_info['user_id'], $set_info['photoset_id'], 1, $per_page);
            } else {
                // No sorting, no limit: fetch full album
                $set_result = flickr_justified_get_full_photoset_photos($set_info['user_id'], $set_info['photoset_id']);
            }

            // Check if album fetch was rate limited; keep partial photos if present
            $set_rate_limited = !empty($set_result['rate_limited']);
            if ($set_rate_limited && (empty($set_result['photos']) || !is_array($set_result['photos']))) {
                $rate_limited = true;
                break;
            }
            if ($set_rate_limited) {
                $rate_limited = true;
            }

            $set_photos = isset($set_result['photos']) && is_array($set_result['photos']) ? $set_result['photos'] : [];

            if (null !== $remaining_limit) {
                // For input order: limit normally
                $set_photos = array_slice($set_photos, 0, $remaining_limit);
            }

            $added_count = 0;
            foreach ($set_photos as $photo_data) {
                // Stop processing if we hit rate limiting
                if ($rate_limited) {
                    break;
                }

                if (null !== $remaining_limit && $remaining_limit <= 0) {
                    break;
                }

                // URL string format (legacy/default)
                $photo_url = is_string($photo_data) ? trim($photo_data) : '';
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
                    // Try to get stats from cache (either photo_stats or photo_info)
                    $photo_id = flickr_justified_extract_photo_id($photo_url);
                    if ($photo_id) {
                        // First try photo_stats cache
                        $stats_cache_key = ['photo_stats', $photo_id];
                        $stats = FlickrJustifiedCache::get($stats_cache_key);

                        // If not found, try extracting from cached photo_info (from photoset response)
                        if (empty($stats) || isset($stats['not_found'])) {
                            $stats = FlickrJustifiedCache::get_photo_stats($photo_id);
                        }

                        if (!empty($stats) && !isset($stats['not_found'])) {
                            $item['stats'] = $stats;
                            $item['views'] = isset($stats['views']) ? (int) $stats['views'] : 0;
                            $item['comments'] = isset($stats['comments']) ? (int) $stats['comments'] : 0;
                            $item['favorites'] = isset($stats['favorites']) ? (int) $stats['favorites'] : 0;
                        }
                    }
                }

                // Fetch dimensions for non-Flickr images (Flickr dimensions come from API in rendering phase)
                if (!$is_flickr) {
                    $dims = flickr_justified_get_external_image_dimensions($photo_url);
                    if ($dims && isset($dims['width'], $dims['height'])) {
                        $item['width'] = $dims['width'];
                        $item['height'] = $dims['height'];
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
                // If this was an album URL that returned no photos, don't add the album URL as a photo
                // This happens when albums are rate-limited or fail to fetch
                if ($set_info) {
                    // Skip album URLs that couldn't be fetched
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log(sprintf('Skipping album URL with no photos (likely rate-limited): %s', $url));
                    }
                    continue;
                }

                // For non-album URLs that couldn't be processed, add as-is
                // (this is for direct image URLs)
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
            // Only use cached stats; do not fetch live to avoid blocking render.
            $photo_id = flickr_justified_extract_photo_id($url);
            if ($photo_id) {
                $stats_cache_key = ['photo_stats', $photo_id];
                $stats = FlickrJustifiedCache::get($stats_cache_key);
                if (!empty($stats) && !isset($stats['not_found'])) {
                    $item['stats'] = $stats;
                    $item['views'] = isset($stats['views']) ? (int) $stats['views'] : 0;
                    $item['comments'] = isset($stats['comments']) ? (int) $stats['comments'] : 0;
                    $item['favorites'] = isset($stats['favorites']) ? (int) $stats['favorites'] : 0;
                }
            }
        }

        // Fetch dimensions for non-Flickr images (Flickr dimensions come from API in rendering phase)
        if (!$is_flickr) {
            $dims = flickr_justified_get_external_image_dimensions($url);
            if ($dims && isset($dims['width'], $dims['height'])) {
                $item['width'] = $dims['width'];
                $item['height'] = $dims['height'];
            }
        }

        $photo_items[] = $item;
        $position_counter++;

        if (null !== $remaining_limit) {
            $remaining_limit--;
        }
    }

    // Show user-friendly message when rate limited and no photos available
    if ($rate_limited && empty($photo_items)) {
        return sprintf(
            '<div class="flickr-justified-notice" role="status" aria-live="polite" style="padding: 30px; text-align: center; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; color: #856404; font-size: 16px; line-height: 1.6; margin: 20px 0;">
                <p style="margin: 0;"><strong>%s</strong></p>
                <p style="margin: 10px 0 0;">%s</p>
            </div>',
            esc_html__('This gallery is temporarily unavailable.', 'flickr-justified-block'),
            esc_html__('We are waiting for Flickr to allow more requests. Please check back shortly.', 'flickr-justified-block')
        );
    }

    // Log when we return partial results due to rate limiting
    if ($rate_limited && defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            'Flickr Justified Block: Rate limit detected, rendering partial gallery with %d photos (out of %d URLs requested)',
            count($photo_items),
            count($url_lines)
        ));
    }

    if (empty($photo_items)) {
        return '';
    }

    // For views_desc, photos are already sorted and limited by get_top_viewed_photos_from_set()
    // For input order, no sorting needed and limit already applied during fetch

    // Generate unique ID for this block instance
    // Use target gallery ID if provided (from async loading), otherwise generate new one
    $block_id = isset($attributes['_target_gallery_id']) ? $attributes['_target_gallery_id'] : 'flickr-justified-' . uniqid();

    $gallery_html = flickr_justified_render_justified_gallery(
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

    // Prepend message if rate limited with partial results
    if ($rate_limited && !empty($photo_items)) {
        $partial_count = count($photo_items);
        $partial_message = sprintf(
            '<div class="flickr-justified-notice" role="status" aria-live="polite" style="padding: 20px; text-align: center; background: #d1ecf1; border: 1px solid #17a2b8; border-radius: 8px; color: #0c5460; font-size: 15px; line-height: 1.5; margin: 20px 0 15px;">
                <p style="margin: 0;">%s</p>
            </div>',
            esc_html(sprintf(__('Showing %d photos while we wait for more from Flickr. Check back soon for the full gallery.', 'flickr-justified-block'), $partial_count))
        );
        $gallery_html = $partial_message . $gallery_html;
    }

    // Prepend message if using fallback fetch for views_desc (cache not ready)
    if ($used_fallback_fetch && 'views_desc' === $sort_order && !empty($photo_items)) {
        $fallback_message = sprintf(
            '<div class="flickr-justified-notice flickr-justified-loading-notice" role="status" aria-live="polite" style="padding: 16px 20px; text-align: center; background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; color: #856404; font-size: 14px; line-height: 1.5; margin: 20px 0 15px;">
                <p style="margin: 0;">%s</p>
            </div>',
            esc_html__('Error fetching top viewed photos, showing photos in set order. Check back later while we refresh the top photos.', 'flickr-justified-block')
        );
        $gallery_html = $fallback_message . $gallery_html;
    }

    return $gallery_html;
}
