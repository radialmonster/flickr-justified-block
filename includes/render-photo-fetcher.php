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

    $per_page = 500; // Flickr maximum per page
    $page = 1;
    $all_photos = [];
    $total_pages = 1;
    $total_photos = 0;
    $album_title = '';
    $last_has_more = false;
    $pages_fetched = 0;

    while (true) {
        $page_result = FlickrJustifiedCache::get_photoset_photos($user_id, $photoset_id, $page, $per_page);

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
        $pages_fetched++;

        $last_has_more = !empty($page_result['has_more']);
        if (!$last_has_more) {
            break;
        }

        $next_page = isset($page_result['page']) ? ((int) $page_result['page']) + 1 : $page + 1;
        if ($next_page <= $page) {
            break;
        }

        $page = $next_page;

        if ($page > $total_pages) {
            break;
        }
    }

    $loaded_photos_count = count($all_photos);

    $full_result = [
        'photos' => $all_photos,
        'has_more' => (bool) $last_has_more,
        'total' => $total_photos > 0 ? $total_photos : $loaded_photos_count,
        'page' => 1,
        'pages' => max(1, $total_pages),
        'album_title' => $album_title,
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

    if (!$full_result['has_more'] && $fetched_all_pages && !empty($all_photos)) {
        $cache_duration = 6 * HOUR_IN_SECONDS;
        $configured_duration = flickr_justified_get_admin_setting('get_cache_duration', 0);
        if ($configured_duration > 0) {
            $cache_duration = max(HOUR_IN_SECONDS, (int) $configured_duration);
        }

        FlickrJustifiedCache::set($cache_key, $full_result, $cache_duration);
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
