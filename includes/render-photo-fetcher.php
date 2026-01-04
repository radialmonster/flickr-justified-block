<?php
/**
 * Photo fetching functions for Flickr Justified Block
 *
 * Functions for retrieving photos from Flickr albums, photosets, and direct URLs.
 *
 * @package FlickrJustifiedBlock
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// PHOTOSET/ALBUM FETCHING
// ============================================================================

/**
 * Get full photoset photos (all pages aggregated)
 */
function flickr_justified_get_full_photoset_photos($user_id, $photoset_id) {
    $empty_result = flickr_justified_empty_photoset_result(1);

    if (empty($user_id) || empty($photoset_id) || !is_string($user_id) || !is_string($photoset_id)) {
        return $empty_result;
    }

    $resolved_user_id = FlickrJustifiedCache::resolve_user_id($user_id);
    if (!$resolved_user_id) {
        return $empty_result;
    }

    $cache_key = ['set_full', md5($resolved_user_id . '_' . $photoset_id)];
    $cached_result = FlickrJustifiedCache::get($cache_key);
    if (!empty($cached_result) && is_array($cached_result) && isset($cached_result['photos'])) {
        return $cached_result;
    }

    // Resume from a recent partial aggregate if it exists
    $partial_cache_key = ['set_full_partial', md5($resolved_user_id . '_' . $photoset_id)];
    $partial_cached = FlickrJustifiedCache::get($partial_cache_key);

    $per_page = 500; // Flickr maximum per page
    $page = 1;
    $all_photos = [];
    $all_photo_views = [];
    $all_photo_urls_map = [];
    $total_pages = 1;
    $total_photos = 0;
    $album_title = '';
    $last_has_more = false;
    $pages_fetched = 0;
    $rate_limited = false;
    $incomplete = false;
    $last_successful_page = 0;

    if (is_array($partial_cached)) {
        $all_photos = isset($partial_cached['photos']) && is_array($partial_cached['photos']) ? $partial_cached['photos'] : [];
        $album_title = isset($partial_cached['album_title']) ? $partial_cached['album_title'] : '';
        $total_pages = isset($partial_cached['pages']) ? max(1, (int) $partial_cached['pages']) : 1;
        $total_photos = isset($partial_cached['total']) ? max(0, (int) $partial_cached['total']) : 0;
        $pages_fetched = isset($partial_cached['pages_fetched']) ? max(0, (int) $partial_cached['pages_fetched']) : (count($all_photos) > 0 ? 1 : 0);
        $last_successful_page = isset($partial_cached['last_page']) ? max(0, (int) $partial_cached['last_page']) : $pages_fetched;
        $page = isset($partial_cached['resume_page']) ? max(1, (int) $partial_cached['resume_page']) : ($last_successful_page + 1);
        $incomplete = true; // Any partial cache implies prior incompletion
    }

    $start_time = microtime(true);
    $max_duration = (int) apply_filters('flickr_justified_full_photoset_max_seconds', 20);
    $max_duration = max(5, $max_duration); // Ensure we always allow a reasonable fetch window
    $soft_photo_cap = (int) apply_filters('flickr_justified_full_photoset_max_photos', 0); // 0 = disabled

    while (true) {
        // If a soft cap is configured and already reached, stop before fetching more
        if ($soft_photo_cap > 0 && count($all_photos) >= $soft_photo_cap) {
            $incomplete = true;
            $last_has_more = true;
            break;
        }

        $page_result = FlickrJustifiedCache::get_photoset_photos($user_id, $photoset_id, $page, $per_page);

        // Stop early on rate limiting but keep whatever we already fetched
        if (isset($page_result['rate_limited']) && $page_result['rate_limited']) {
            $rate_limited = true;
            $incomplete = true;
            $last_has_more = true;
            break;
        }

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

        foreach ($page_result['photos'] as $photo) {
            $all_photos[] = $photo;
        }
        // Accumulate views and URL map for set index (when provided)
        if (isset($page_result['photo_views']) && is_array($page_result['photo_views'])) {
            foreach ($page_result['photo_views'] as $pid => $views) {
                $all_photo_views[$pid] = (int) $views;
            }
        }
        if (isset($page_result['photo_urls_map']) && is_array($page_result['photo_urls_map'])) {
            foreach ($page_result['photo_urls_map'] as $pid => $purl) {
                $all_photo_urls_map[$pid] = $purl;
            }
        }
        $pages_fetched++;
        $last_successful_page = $page;

        $last_has_more = !empty($page_result['has_more']);
        if (!$last_has_more) {
            break;
        }

        $next_page = isset($page_result['page']) ? ((int) $page_result['page']) + 1 : $page + 1;
        if ($next_page <= $page) {
            $incomplete = true;
            break;
        }

        $page = $next_page;

        if ($page > $total_pages) {
            break;
        }

        // Safety: bail out if this single request is taking too long (prevent timeouts)
        if ((microtime(true) - $start_time) >= $max_duration) {
            $incomplete = true;
            $last_has_more = true;
            break;
        }

        // Optional safety: prevent unbounded memory growth; still mark partial so loader can continue
        if ($soft_photo_cap > 0 && count($all_photos) >= $soft_photo_cap) {
            $incomplete = true;
            $last_has_more = true;
            break;
        }
    }

    $loaded_photos_count = count($all_photos);

    // Build ordered photo list: prefer membership ordering when available.
    $ordered_photos = $all_photos;
    if (!empty($photoset_id) && !empty($all_photo_urls_map)) {
        $ordered_ids = FlickrJustifiedCache::get_membership_order($photoset_id);
        if (!empty($ordered_ids)) {
            $ordered_photos = [];
            foreach ($ordered_ids as $pid) {
                if (isset($all_photo_urls_map[$pid])) {
                    $ordered_photos[] = $all_photo_urls_map[$pid];
                }
            }
            foreach ($all_photo_urls_map as $pid => $url) {
                if (!in_array($pid, $ordered_ids, true)) {
                    $ordered_photos[] = $url;
                }
            }
        }
    }

    $full_result = [
        'photos' => $ordered_photos,
        'has_more' => (bool) ($last_has_more || $incomplete || $rate_limited),
        'total' => $total_photos > 0 ? $total_photos : $loaded_photos_count,
        'page' => 1,
        'pages' => max(1, $total_pages),
        'album_title' => $album_title,
        'rate_limited' => $rate_limited,
        'partial' => ($incomplete || $rate_limited),
        'pages_fetched' => $pages_fetched,
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

    $should_snapshot_partial = $incomplete || $rate_limited;

    // Build and store set-wide views index when we have data
    if (!empty($all_photo_views) && !empty($all_photo_urls_map)) {
        FlickrJustifiedCache::persist_set_views_index(
            $resolved_user_id,
            $photoset_id,
            $all_photo_views,
            $all_photo_urls_map,
            $should_snapshot_partial,
            $total_photos
        );
    }

    // If views are stale, enqueue a high-priority refresh job for this set.
    if (!empty($photoset_id) && class_exists('FlickrJustifiedCacheWarmer')) {
        $stale_views_threshold = (int) apply_filters('flickr_justified_views_refresh_threshold_hours', 24);
        if ($stale_views_threshold > 0 && FlickrJustifiedCache::views_are_stale_for_set($photoset_id, $stale_views_threshold)) {
            FlickrJustifiedCacheWarmer::enqueue_hot_set($photoset_id, $resolved_user_id);
        }
    }

    if (!$full_result['has_more'] && $fetched_all_pages && !empty($all_photos) && !$rate_limited) {
        $cache_duration = 6 * HOUR_IN_SECONDS;
        $configured_duration = flickr_justified_get_admin_setting('get_cache_duration', 0);
        if ($configured_duration > 0) {
            $cache_duration = max(HOUR_IN_SECONDS, (int) $configured_duration);
        }

        FlickrJustifiedCache::set($cache_key, $full_result, $cache_duration);
        // Clear any partial snapshot now that we have a full set
        FlickrJustifiedCache::delete($partial_cache_key);
    } elseif ($should_snapshot_partial) {
        // Store a lightweight partial snapshot with a short TTL to avoid re-fetching same pages
        $partial_ttl = (int) apply_filters('flickr_justified_full_photoset_partial_ttl', 10 * MINUTE_IN_SECONDS);
        $partial_ttl = max(60, $partial_ttl);
        $resume_page = $last_successful_page > 0 ? ($last_successful_page + 1) : $page;

        $partial_snapshot = [
            // Keep photos but allow developers to disable via filter if size is a concern
            'photos' => apply_filters('flickr_justified_full_photoset_partial_include_photos', true) ? $all_photos : [],
            'album_title' => $album_title,
            'total' => $total_photos,
            'pages' => $total_pages,
            'pages_fetched' => $pages_fetched,
            'last_page' => $last_successful_page,
            'resume_page' => max(1, $resume_page),
            'rate_limited' => $rate_limited,
        ];

        FlickrJustifiedCache::set($partial_cache_key, $partial_snapshot, $partial_ttl);
    }

    return $full_result;
}

// ============================================================================
// ROTATION HELPERS
// ============================================================================

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
 * Extract photo stats from cached info (wrapper for compatibility)
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

// ============================================================================
// ALBUM/SET URL PARSING
// ============================================================================

/**
 * Parse Flickr set/album URL to extract photoset_id and user_id
 *
 * @param string $url Flickr set URL
 * @return array|false Array with photoset_id and user_id, or false if invalid
 */
function flickr_justified_parse_set_url($url) {
    if (empty($url) || !is_string($url)) {
        return false;
    }

    $patterns = [
        '#(?:www\.)?flickr\.com/photos/([^/]+)/sets/(\d+)#i',
        '#(?:www\.)?flickr\.com/photos/([^/]+)/albums/(\d+)#i',
        '#(?:www\.)?flickr\.com/photos/([^/]+)/albums/(\d+)/with/(\d+)#i',
        '#(?:www\.)?flickr\.com/photos/([^/]+)/sets/(\d+)/with/(\d+)#i'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            if (isset($matches[1], $matches[2]) && !empty($matches[1]) && !empty($matches[2])) {
                $result = [
                    'user_id' => trim($matches[1]),
                    'photoset_id' => $matches[2],
                    'url' => $url
                ];

                if (isset($matches[3]) && !empty($matches[3])) {
                    $result['with_photo_id'] = $matches[3];
                }

                return $result;
            }
        }
    }

    return false;
}
