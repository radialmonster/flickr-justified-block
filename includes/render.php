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
 * Safely retrieve a value from the admin settings class.
 *
 * @param string $method  Method name to call on FlickrJustifiedAdminSettings.
 * @param mixed  $default Default value to return when the method is unavailable.
 * @return mixed
 */
function flickr_justified_get_admin_setting($method, $default = null) {
    if (!is_string($method) || empty($method)) {
        return $default;
    }

    if (!class_exists('FlickrJustifiedAdminSettings')) {
        return $default;
    }

    if (!is_callable(['FlickrJustifiedAdminSettings', $method])) {
        return $default;
    }

    $value = call_user_func(['FlickrJustifiedAdminSettings', $method]);

    return null === $value ? $default : $value;
}

/**
 * Retrieve the ordered list of Flickr size identifiers used throughout the plugin.
 *
 * @param bool $include_thumbnails When true, include square thumbnail sizes.
 * @return array<int, string>
 */
function flickr_justified_get_available_flickr_sizes($include_thumbnails = false) {
    static $standard_sizes = null;
    static $sizes_with_thumbnails = null;

    if (null === $standard_sizes) {
        $standard_sizes = [
            'original', 'large6k', 'large5k', 'largef', 'large4k', 'large3k',
            'large2048', 'large1600', 'large1024', 'large',
            'medium800', 'medium640', 'medium500', 'medium',
            'small400', 'small320', 'small240',
        ];
    }

    if (!$include_thumbnails) {
        return $standard_sizes;
    }

    if (null === $sizes_with_thumbnails) {
        $sizes_with_thumbnails = array_merge(
            $standard_sizes,
            ['thumbnail100', 'thumbnail150s', 'thumbnail75s']
        );
    }

    return $sizes_with_thumbnails;
}

/**
 * Encode data for safe output within HTML attributes.
 *
 * @param mixed $data Data to encode.
 * @return string
 */
function flickr_justified_encode_json_attr($data) {
    $encoded = function_exists('wp_json_encode') ? wp_json_encode($data) : json_encode($data);

    return is_string($encoded) ? $encoded : '';
}

/**
 * Determine whether a URL points to a Flickr photo page.
 *
 * @param string $url Potential Flickr photo URL.
 * @return bool
 */
function flickr_justified_is_flickr_photo_url($url) {
    if (!is_string($url) || '' === $url) {
        return false;
    }

    return (bool) preg_match('#(?:www\.)?flickr\.com/photos/[^/]+/\d+#i', $url);
}

/**
 * Extract the Flickr photo ID from a photo URL.
 *
 * @param string $url Flickr photo URL.
 * @return string Empty string when no ID can be located.
 */
function flickr_justified_extract_photo_id($url) {
    if (!is_string($url) || '' === $url) {
        return '';
    }

    if (preg_match('#flickr\.com/photos/[^/]+/(\d+)#i', $url, $matches) && isset($matches[1])) {
        return $matches[1];
    }

    return '';
}

/**
 * Build the Flickr attribution URL for a photo that appears within a specific album.
 *
 * @param string $photo_url   Base Flickr photo URL.
 * @param string $photoset_id Flickr album/photoset identifier.
 * @param string $user_id     Flickr user or NSID. Optional when derivable from URL.
 *
 * @return string Attribution URL or empty string when unavailable.
 */
function flickr_justified_build_album_photo_attribution_url($photo_url, $photoset_id, $user_id = '') {
    if (!is_string($photo_url) || '' === $photo_url) {
        return '';
    }

    if (!is_string($photoset_id) || '' === $photoset_id) {
        return '';
    }

    $photo_id = flickr_justified_extract_photo_id($photo_url);
    if ('' === $photo_id) {
        return '';
    }

    if (!is_string($user_id) || '' === $user_id) {
        if (preg_match('#flickr\.com/photos/([^/]+)/#i', $photo_url, $matches) && !empty($matches[1])) {
            $user_id = $matches[1];
        }
    }

    if (!is_string($user_id) || '' === $user_id) {
        return '';
    }

    return sprintf(
        'https://flickr.com/photos/%s/%s/in/album-%s/',
        rawurlencode($user_id),
        rawurlencode($photo_id),
        rawurlencode($photoset_id)
    );
}

/**
 * Provide a consistent empty response for paginated photoset requests.
 *
 * @param int $page Requested page number.
 * @return array
 */
function flickr_justified_empty_photoset_result($page = 1) {
    $page = max(1, (int) $page);

    return [
        'photos'      => [],
        'has_more'    => false,
        'total'       => 0,
        'page'        => $page,
        'pages'       => 1,
        'album_title' => '',
    ];
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

// ============================================================================
// WRAPPER FUNCTIONS FOR BACKWARD COMPATIBILITY
// These delegate to FlickrJustifiedCache class
// ============================================================================

/**
 * Get photo info with dimensions (wrapper for cache class)
 */
function flickr_justified_get_flickr_image_sizes_with_dimensions($page_url, $requested_sizes = ['large', 'original'], $needs_metadata = false) {
    if (!preg_match('#flickr\.com/photos/[^/]+/(\d+)#', $page_url, $matches)) {
        return [];
    }

    $photo_id = $matches[1];
    return FlickrJustifiedCache::get_photo_sizes($photo_id, $page_url, $requested_sizes, $needs_metadata);
}

/**
 * Get photo stats (wrapper for cache class)
 */
function flickr_justified_get_photo_stats($photo_id) {
    return FlickrJustifiedCache::get_photo_stats($photo_id);
}

/**
 * Get photo info (wrapper for cache class)
 */
function flickr_justified_get_photo_info($photo_id, $force_refresh = false) {
    return FlickrJustifiedCache::get_photo_info($photo_id, $force_refresh);
}

/**
 * Resolve user ID (wrapper for cache class)
 */
function flickr_justified_resolve_user_id($username) {
    return FlickrJustifiedCache::resolve_user_id($username);
}

/**
 * Get photoset info (wrapper for cache class)
 */
function flickr_justified_get_photoset_info($user_id, $photoset_id) {
    return FlickrJustifiedCache::get_photoset_info($user_id, $photoset_id);
}

/**
 * Get photoset photos paginated (wrapper for cache class)
 */
function flickr_justified_get_photoset_photos_paginated($user_id, $photoset_id, $page = 1, $per_page = 50) {
    return FlickrJustifiedCache::get_photoset_photos($user_id, $photoset_id, $page, $per_page);
}

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

        $all_photos = array_merge($all_photos, array_values($page_result['photos']));
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
// ALBUM/SET PARSING
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

// ============================================================================
// RENDERING
// ============================================================================

/**
 * Render with justified gallery layout
 */
function flickr_justified_render_justified_gallery($photos, $block_id, $gap, $image_size, $responsive_settings, $row_height_mode, $row_height, $max_viewport_height, $single_image_alignment, $set_metadata = [], $context = []) {

    $photo_limit = isset($context['photo_limit']) ? max(0, (int) $context['photo_limit']) : 0;
    $sort_order = isset($context['sort_order']) && 'views_desc' === $context['sort_order'] ? 'views_desc' : 'input';
    $loaded_count = is_array($photos) ? count($photos) : 0;

    // Get admin breakpoints
    $breakpoints = flickr_justified_get_admin_setting('get_breakpoints', []);

    // Get attribution text for consistent PhotoSwipe button labeling
    $attribution_text = flickr_justified_get_admin_setting('get_attribution_text', 'Flickr');

    // Generate simple structure - JavaScript will organize into responsive rows
    $responsive_attr = esc_attr(flickr_justified_encode_json_attr($responsive_settings));
    $breakpoints_attr = esc_attr(flickr_justified_encode_json_attr($breakpoints));
    $set_metadata_attr = !empty($set_metadata) ? esc_attr(flickr_justified_encode_json_attr($set_metadata)) : '';
    $output = sprintf(
        '<div id="%s" class="flickr-justified-grid" style="--gap: %dpx;" data-responsive-settings="%s" data-breakpoints="%s" data-row-height-mode="%s" data-row-height="%d" data-max-viewport-height="%d" data-single-image-alignment="%s" data-use-builtin-lightbox="%s" data-set-metadata="%s" data-attribution-text="%s" data-photo-limit="%d" data-sort-order="%s" data-loaded-count="%d">',
        esc_attr($block_id),
        (int) $gap,
        $responsive_attr,
        $breakpoints_attr,
        esc_attr($row_height_mode),
        (int) $row_height,
        (int) $max_viewport_height,
        esc_attr($single_image_alignment),
        '1',
        $set_metadata_attr,
        esc_attr($attribution_text),
        (int) $photo_limit,
        esc_attr($sort_order),
        (int) $loaded_count
    );

    if (!is_array($photos)) {
        $photos = [];
    }

    foreach ($photos as $photo) {
        if (is_string($photo)) {
            $photo = ['url' => $photo];
        }

        $url = isset($photo['url']) ? esc_url($photo['url']) : '';
        if ('' === $url) {
            continue;
        }

        $is_flickr = $photo['is_flickr'] ?? flickr_justified_is_flickr_photo_url($url);
        $position = isset($photo['position']) ? (int) $photo['position'] : null;
        $stats = [];
        $attribution_page_url = isset($photo['attribution_url']) ? esc_url($photo['attribution_url']) : $url;
        if ('' === $attribution_page_url) {
            $attribution_page_url = $url;
        }

        if ($is_flickr) {
            $available_sizes = flickr_justified_get_available_flickr_sizes(true);

            $image_data = flickr_justified_get_flickr_image_sizes_with_dimensions($url, $available_sizes, true);

            if (!empty($photo['stats']) && is_array($photo['stats'])) {
                $stats = $photo['stats'];
                if (is_array($image_data) && !isset($image_data['_stats'])) {
                    $image_data['_stats'] = $stats;
                }
            } elseif (!empty($image_data['_stats']) && is_array($image_data['_stats'])) {
                $stats = $image_data['_stats'];
            }

            $rotation = 0;
            if (isset($photo['rotation'])) {
                $rotation = flickr_justified_normalize_rotation($photo['rotation']);
            } elseif (isset($image_data['_rotation'])) {
                $rotation = flickr_justified_normalize_rotation($image_data['_rotation']);
            } elseif (!empty($image_data['_photo_info'])) {
                $rotation = flickr_justified_extract_rotation_from_info($image_data['_photo_info']);
            }

            $display_src = isset($image_data[$image_size]['url']) ? $image_data[$image_size]['url'] : '';
            $dimensions = isset($image_data[$image_size]) ? $image_data[$image_size] : null;

            // For PhotoSwipe, select size appropriate for high-res displays (around 2-3x screen width)
            $best_lightbox_size = flickr_justified_select_best_size($image_data, 3500, 3500);

            if ($best_lightbox_size && isset($image_data[$best_lightbox_size])) {
                $selected_width = $image_data[$best_lightbox_size]['width'];
                if ($selected_width < 3000) {
                    $best_lightbox_size = flickr_justified_select_best_size($image_data, PHP_INT_MAX, PHP_INT_MAX);
                }
            } else {
                $best_lightbox_size = flickr_justified_select_best_size($image_data, PHP_INT_MAX, PHP_INT_MAX);
            }

            $lightbox_src = '';
            $lightbox_dimensions = null;
            if ($best_lightbox_size && isset($image_data[$best_lightbox_size]['url'])) {
                $lightbox_src = $image_data[$best_lightbox_size]['url'];
                $lightbox_dimensions = $image_data[$best_lightbox_size];
            }

            if (empty($display_src) && !empty($lightbox_src)) {
                $display_src = $lightbox_src;
                $dimensions = $lightbox_dimensions;
            }
            if (empty($lightbox_src) && !empty($display_src)) {
                $lightbox_src = $display_src;
            }

            if (empty($display_src)) {
                $error_mode = flickr_justified_get_admin_setting('get_privacy_error_mode', 'show_placeholder');

                if ($error_mode === 'show_nothing') {
                    continue;
                }

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

            $data_attrs = '';
            $rotated_display_dimensions = flickr_justified_apply_rotation_to_dimensions($dimensions ?? [], $rotation);
            $rotated_lightbox_dimensions = flickr_justified_apply_rotation_to_dimensions($lightbox_dimensions ?? [], $rotation);

            $width_attr = null;
            $height_attr = null;
            if (isset($rotated_lightbox_dimensions['width'], $rotated_lightbox_dimensions['height']) &&
                $rotated_lightbox_dimensions['width'] > 0 && $rotated_lightbox_dimensions['height'] > 0) {
                $width_attr = (int) $rotated_lightbox_dimensions['width'];
                $height_attr = (int) $rotated_lightbox_dimensions['height'];
            } elseif (isset($rotated_display_dimensions['width'], $rotated_display_dimensions['height']) &&
                $rotated_display_dimensions['width'] > 0 && $rotated_display_dimensions['height'] > 0) {
                $width_attr = (int) $rotated_display_dimensions['width'];
                $height_attr = (int) $rotated_display_dimensions['height'];
            }

            if (null !== $width_attr && null !== $height_attr) {
                $data_attrs = sprintf(' data-width="%d" data-height="%d"', $width_attr, $height_attr);
            }

            if ($rotation) {
                $data_attrs .= sprintf(' data-rotation="%d"', (int) $rotation);
            }

            $lightbox_class = 'flickr-builtin-lightbox';
            $gallery_group_attribute = 'data-gallery';
            $gallery_group = esc_attr($block_id);

            $attribution_attrs = sprintf(' data-flickr-page="%s" data-flickr-attribution-text="%s"',
                esc_attr($attribution_page_url),
                esc_attr($attribution_text)
            );

            $attribution_attrs .= sprintf(' data-caption="%s" data-title="%s" title="%s"',
                esc_attr($attribution_text),
                esc_attr($attribution_text),
                esc_attr($attribution_text)
            );

            $views = isset($stats['views']) ? (int) $stats['views'] : 0;
            $comments = isset($stats['comments']) ? (int) $stats['comments'] : 0;
            $favorites = isset($stats['favorites']) ? (int) $stats['favorites'] : 0;

            $card_attributes = ['class="flickr-card"', 'style="position: relative;"'];
            if ($rotation) {
                $card_attributes[] = 'data-rotation="' . esc_attr($rotation) . '"';
            }
            if (null !== $position) {
                $card_attributes[] = 'data-position="' . esc_attr($position) . '"';
            }
            $card_attributes[] = 'data-views="' . esc_attr($views) . '"';
            $card_attributes[] = 'data-comments="' . esc_attr($comments) . '"';
            $card_attributes[] = 'data-favorites="' . esc_attr($favorites) . '"';

            if (null !== $width_attr && null !== $height_attr) {
                $card_attributes[] = 'data-width="' . esc_attr($width_attr) . '"';
                $card_attributes[] = 'data-height="' . esc_attr($height_attr) . '"';
            }

            $img_extra_attrs = [];
            if (null !== $width_attr && null !== $height_attr) {
                $img_extra_attrs[] = 'data-width="' . esc_attr($width_attr) . '"';
                $img_extra_attrs[] = 'data-height="' . esc_attr($height_attr) . '"';
            }

            if ($rotation) {
                $img_extra_attrs[] = 'data-rotation="' . esc_attr($rotation) . '"';
                $img_extra_attrs[] = 'style="transform: rotate(' . esc_attr($rotation) . 'deg); transform-origin: center center;"';
            }

            $img_attr_string = '';
            if (!empty($img_extra_attrs)) {
                $img_attr_string = ' ' . implode(' ', $img_extra_attrs);
            }

            $output .= sprintf(
                '<article %s>
                    <a href="%s" class="%s" %s="%s" %s%s>
                        <img src="%s" loading="lazy" decoding="async" alt=""%s>
                    </a>
                </article>',
                implode(' ', $card_attributes),
                esc_url($lightbox_src),
                esc_attr($lightbox_class),
                esc_attr($gallery_group_attribute),
                esc_attr($gallery_group),
                $data_attrs,
                $attribution_attrs,
                esc_url($display_src),
                $img_attr_string
            );
        } else {
            $views = isset($photo['views']) ? (int) $photo['views'] : 0;
            $comments = isset($photo['comments']) ? (int) $photo['comments'] : 0;
            $favorites = isset($photo['favorites']) ? (int) $photo['favorites'] : 0;

            $card_attributes = ['class="flickr-card"', 'style="position: relative;"'];
            if (null !== $position) {
                $card_attributes[] = 'data-position="' . esc_attr($position) . '"';
            }
            $card_attributes[] = 'data-views="' . esc_attr($views) . '"';
            $card_attributes[] = 'data-comments="' . esc_attr($comments) . '"';
            $card_attributes[] = 'data-favorites="' . esc_attr($favorites) . '"';

            $lightbox_class = 'flickr-builtin-lightbox';
            $gallery_group_attribute = 'data-gallery';
            $gallery_group = esc_attr($block_id);

            $output .= sprintf(
                '<article %s>
                    <a href="%s" class="%s" %s="%s">
                        <img src="%s" loading="lazy" decoding="async" alt="">
                    </a>
                </article>',
                implode(' ', $card_attributes),
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

    // Check if any URLs are albums
    foreach ($final_urls as $url) {
        $set_info = flickr_justified_parse_set_url($url);
        if ($set_info && !empty($set_info['photoset_id'])) {
            // Quick check: is this album cached?
            $cache_key = ['set_full', md5($set_info['user_id'] . '_' . $set_info['photoset_id'])];
            $cached = FlickrJustifiedCache::get($cache_key);

            if (empty($cached)) {
                // Album not cached - use async loading to prevent timeout
                return true;
            }
        }
    }

    return false;
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

    // Check if we should use async loading (for large uncached albums to prevent timeouts)
    $use_async_loading = flickr_justified_should_use_async_loading($urls);

    if ($use_async_loading) {
        // Return loading placeholder that will load via AJAX
        $block_id = 'flickr-justified-async-' . uniqid();
        $attributes_json = wp_json_encode($attributes);

        return sprintf(
            '<div id="%s" class="flickr-justified-loading" data-attributes="%s" data-target-id="%s">
                <p class="flickr-loading-icon"><span class="dashicons dashicons-update-alt"></span></p>
                <p>%s</p>
            </div>
            <script>
            (function() {
                var container = document.getElementById("%s");
                if (!container) return;

                var targetGalleryId = container.getAttribute("data-target-id");
                var retryCount = 0;
                var maxRetries = 1;

                function loadGallery() {
                    var xhr = new XMLHttpRequest();
                    xhr.open("POST", "%s", true);
                    xhr.timeout = 10000; // 10 second timeout
                    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");

                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response.success && response.data && response.data.html) {
                                    container.outerHTML = response.data.html;
                                    // Find the specific gallery that was just loaded using its unique ID
                                    var newBlock = targetGalleryId ? document.getElementById(targetGalleryId) : document.querySelector(".flickr-justified-grid");
                                    if (newBlock) {
                                        try {
                                            // Initialize justified layout
                                            if (window.initJustifiedGallery) {
                                                console.log("Flickr Gallery: Initializing justified layout");
                                                window.initJustifiedGallery();
                                            } else {
                                                console.warn("Flickr Gallery: initJustifiedGallery not found");
                                            }
                                            // Initialize lazy loading for async-loaded galleries
                                            if (window.initFlickrAlbumLazyLoading) {
                                                console.log("Flickr Gallery: Initializing lazy loading");
                                                window.initFlickrAlbumLazyLoading();
                                            } else {
                                                console.warn("Flickr Gallery: initFlickrAlbumLazyLoading not found");
                                            }
                                            // Trigger PhotoSwipe initialization
                                            console.log("Flickr Gallery: Triggering PhotoSwipe initialization event");
                                            var event = new CustomEvent("flickr-gallery-updated", { detail: { gallery: newBlock } });
                                            document.dispatchEvent(event);
                                            console.log("Flickr Gallery: Initialization complete");
                                        } catch(initError) {
                                            console.error("Flickr Gallery: Initialization failed but gallery HTML is visible:", initError);
                                            console.error("Error stack:", initError.stack);
                                        }
                                    } else {
                                        console.error("Flickr Gallery: Could not find gallery element after loading. Target ID:", targetGalleryId);
                                    }
                                } else {
                                    console.error("Flickr Gallery: Invalid response data:", response);
                                    container.innerHTML = "<p>Error loading gallery</p>";
                                }
                            } catch(e) {
                                container.innerHTML = "<p>Error parsing gallery data: " + e.message + "</p>";
                            }
                        } else {
                            handleError("Server error (HTTP " + xhr.status + ")");
                        }
                    };

                    xhr.onerror = function() {
                        handleError("Network error");
                    };

                    xhr.ontimeout = function() {
                        handleError("Request timed out");
                    };

                    function handleError(errorType) {
                        if (retryCount < maxRetries) {
                            retryCount++;
                            container.innerHTML = "<p class=\"flickr-loading-retry\">Loading failed, retrying...</p>";
                            setTimeout(loadGallery, 2000); // Retry after 2 seconds
                        } else {
                            container.innerHTML = "<p>Failed to load gallery: " + errorType + "</p>";
                        }
                    }

                    xhr.send("action=flickr_justified_load_async&attributes=" + encodeURIComponent(%s));
                }

                loadGallery();
            })();
            </script>',
            esc_attr($block_id),
            esc_attr($attributes_json),
            esc_attr('flickr-justified-' . uniqid()),
            esc_html__('Loading gallery...', 'flickr-justified-block'),
            esc_js($block_id),
            esc_url(admin_url('admin-ajax.php')),
            esc_js($attributes_json)
        );
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

    // Split URLs by lines and clean them, then handle multiple URLs on same line
    $url_lines = array_filter(array_map('trim', preg_split('/\R/u', $urls)));

    // Further split any lines that contain multiple URLs (common when copy-pasting)
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
    $url_lines = array_filter($final_urls);

    if (empty($url_lines)) {
        return '';
    }

    $max_photos = isset($attributes['maxPhotos']) ? max(0, (int) $attributes['maxPhotos']) : 0;
    $sort_order = isset($attributes['sortOrder']) ? $attributes['sortOrder'] : 'input';
    if (!in_array($sort_order, ['input', 'views_desc'], true)) {
        $sort_order = 'input';
    }

    $needs_stats = ('views_desc' === $sort_order);
    $remaining_limit = ($max_photos > 0 && 'views_desc' !== $sort_order) ? $max_photos : null;
    $photo_items = [];
    $set_metadata = [];
    $position_counter = 0;
    $rate_limited = false; // Track if we hit rate limiting

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
                $set_result = flickr_justified_get_full_photoset_photos($set_info['user_id'], $set_info['photoset_id']);
            } else {
                $per_page = 50;
                if (null !== $remaining_limit) {
                    $per_page = max(1, min(50, $remaining_limit));
                }

                $set_result = flickr_justified_get_photoset_photos_paginated($set_info['user_id'], $set_info['photoset_id'], 1, $per_page);
            }

            // Check if album fetch was rate limited
            if (isset($set_result['rate_limited']) && $set_result['rate_limited']) {
                $rate_limited = true;
                break;
            }

            $set_photos = isset($set_result['photos']) && is_array($set_result['photos']) ? $set_result['photos'] : [];

            if ('views_desc' !== $sort_order && null !== $remaining_limit) {
                $set_photos = array_slice($set_photos, 0, $remaining_limit);
            }

            $added_count = 0;
            foreach ($set_photos as $photo_url) {
                // Stop processing if we hit rate limiting
                if ($rate_limited) {
                    break;
                }

                if (null !== $remaining_limit && $remaining_limit <= 0) {
                    break;
                }

                $photo_url = trim($photo_url);
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
                    $photo_id = flickr_justified_extract_photo_id($photo_url);
                    if ($photo_id) {
                        $stats = flickr_justified_get_photo_stats($photo_id);

                        // Check for rate limiting
                        if (isset($stats['rate_limited']) && $stats['rate_limited']) {
                            $rate_limited = true;
                            // Don't add this photo since we don't have its stats
                            break;
                        }

                        if (!empty($stats) && is_array($stats)) {
                            $item['stats'] = $stats;
                            $item['views'] = isset($stats['views']) ? (int) $stats['views'] : 0;
                            $item['comments'] = isset($stats['comments']) ? (int) $stats['comments'] : 0;
                            $item['favorites'] = isset($stats['favorites']) ? (int) $stats['favorites'] : 0;
                        }
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
            $photo_id = flickr_justified_extract_photo_id($url);
            if ($photo_id) {
                $stats = flickr_justified_get_photo_stats($photo_id);

                // Check for rate limiting
                if (isset($stats['rate_limited']) && $stats['rate_limited']) {
                    $rate_limited = true;
                    // Stop processing, don't add this photo
                    break;
                }

                if (!empty($stats) && is_array($stats)) {
                    $item['stats'] = $stats;
                    $item['views'] = isset($stats['views']) ? (int) $stats['views'] : 0;
                    $item['comments'] = isset($stats['comments']) ? (int) $stats['comments'] : 0;
                    $item['favorites'] = isset($stats['favorites']) ? (int) $stats['favorites'] : 0;
                }
            }
        }

        $photo_items[] = $item;
        $position_counter++;

        if (null !== $remaining_limit) {
            $remaining_limit--;
        }
    }

    if (empty($photo_items)) {
        return '';
    }

    // Log when we return partial results due to rate limiting
    if ($rate_limited && defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            'Flickr Justified Block: Rate limit detected, rendering partial gallery with %d photos (out of %d URLs requested)',
            count($photo_items),
            count($url_lines)
        ));
    }

    if ('views_desc' === $sort_order) {
        usort($photo_items, static function ($a, $b) {
            $views_a = isset($a['views']) ? (int) $a['views'] : 0;
            $views_b = isset($b['views']) ? (int) $b['views'] : 0;

            if ($views_a === $views_b) {
                $pos_a = isset($a['position']) ? (int) $a['position'] : 0;
                $pos_b = isset($b['position']) ? (int) $b['position'] : 0;
                return $pos_a <=> $pos_b;
            }

            return $views_b <=> $views_a;
        });
    }

    if ($max_photos > 0 && count($photo_items) > $max_photos) {
        $photo_items = array_slice($photo_items, 0, $max_photos);
    }

    // Generate unique ID for this block instance
    $block_id = 'flickr-justified-' . uniqid();

    return flickr_justified_render_justified_gallery(
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
}

/**
 * AJAX handler for async block loading.
 * Renders the block asynchronously to prevent gateway timeouts.
 */
function flickr_justified_ajax_load_async() {
    // No nonce check needed - this is public content rendering
    // The same content would be visible on page load

    if (!isset($_POST['attributes'])) {
        wp_send_json_error('No attributes provided');
    }

    $attributes_json = stripslashes($_POST['attributes']);
    $attributes = json_decode($attributes_json, true);

    if (!is_array($attributes)) {
        wp_send_json_error('Invalid attributes');
    }

    // Temporarily disable async loading to render normally
    // (we're already in async context, no need to recurse)
    add_filter('flickr_justified_force_sync_render', '__return_true');

    $html = flickr_justified_render_block($attributes);

    remove_filter('flickr_justified_force_sync_render', '__return_true');

    wp_send_json_success(['html' => $html]);
}
add_action('wp_ajax_flickr_justified_load_async', 'flickr_justified_ajax_load_async');
add_action('wp_ajax_nopriv_flickr_justified_load_async', 'flickr_justified_ajax_load_async');
