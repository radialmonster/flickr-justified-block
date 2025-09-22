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
 * Fallback: Map API sizes directly to requested sizes (when direct URL construction fails)
 */
function flickr_justified_map_api_sizes_to_requested($api_sizes, $requested_sizes) {
    $size_mapping = [
        'original' => ['Original'],
        'large2048' => ['Large 2048', 'Original'],
        'large1600' => ['Large 1600', 'Large 2048', 'Original'],
        'large' => ['Large', 'Large 1600', 'Large 2048', 'Original'],
        'medium' => ['Medium', 'Medium 640', 'Large', 'Large 1600']
    ];

    $result = [];

    foreach ($requested_sizes as $requested_size) {
        $preferred_labels = $size_mapping[$requested_size] ?? [$requested_size];

        foreach ($preferred_labels as $label) {
            foreach ($api_sizes as $size_info) {
                if (isset($size_info['label'], $size_info['source']) &&
                    $size_info['label'] === $label && !empty($size_info['source'])) {
                    $result[$requested_size] = esc_url_raw($size_info['source']);
                    break 2;
                }
            }
        }
    }

    return $result;
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

/**
 * Enhanced version of flickr_justified_get_flickr_image_sizes that includes dimensions
 */
function flickr_justified_get_flickr_image_sizes_with_dimensions($page_url, $requested_sizes = ['large', 'original']) {
    if (!preg_match('#flickr\.com/photos/[^/]+/(\d+)(?:/|$)#', $page_url, $matches)) {
        return [];
    }

    $photo_id = $matches[1];
    $cache_key = 'flickr_justified_dims_' . $photo_id . '_' . md5(implode(',', $requested_sizes));

    // Check cache first
    $cached_result = get_transient($cache_key);
    if (!empty($cached_result) && is_array($cached_result)) {
        return $cached_result;
    }

    // Get API key from settings
    $api_key = '';
    if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_api_key')) {
        $api_key = FlickrJustifiedAdminSettings::get_api_key();
    }

    if (empty($api_key)) {
        // Debug: Log when API key is missing
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: No API key found for photo ID: ' . $photo_id);
        }
        return [];
    }

    // Debug: Log that we're making an API call
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Flickr Justified Block: Making API call for photo ID: ' . $photo_id);
    }

    // Get available sizes from API with dimensions
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: API request error for photo ID ' . $photo_id . ': ' . $response->get_error_message());
        }
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data['sizes']['size'])) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Flickr Justified Block: No sizes data returned for photo ID ' . $photo_id . '. Response: ' . $body);
        }
        return [];
    }

    // Debug: Log successful API call
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Flickr Justified Block: Successfully retrieved ' . count($data['sizes']['size']) . ' sizes for photo ID: ' . $photo_id);
    }

    // Build result with URLs and dimensions
    $result = flickr_justified_map_api_sizes_to_requested_with_dims($data['sizes']['size'], $requested_sizes);

    if (!empty($result)) {
        // Cache the results
        $cache_duration = WEEK_IN_SECONDS;
        if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_cache_duration')) {
            $cache_duration = FlickrJustifiedAdminSettings::get_cache_duration();
        }
        set_transient($cache_key, $result, $cache_duration);
    }

    return $result;
}

/**
 * Map API sizes to requested sizes including dimensions
 */
function flickr_justified_map_api_sizes_to_requested_with_dims($api_sizes, $requested_sizes) {
    $size_mapping = [
        'original' => ['Original'],
        'large2048' => ['Large 2048', 'Original'],
        'large1600' => ['Large 1600', 'Large 2048', 'Original'],
        'large' => ['Large', 'Large 1600', 'Large 2048', 'Original'],
        'medium' => ['Medium', 'Medium 640', 'Large', 'Large 1600']
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
                        'width' => (int)$size_info['width'],
                        'height' => (int)$size_info['height']
                    ];
                    break 2;
                }
            }
        }
    }

    return $result;
}


/**
 * Render with justified gallery layout
 */
function flickr_justified_render_justified_gallery($url_lines, $block_id, $gap, $image_size, $lightbox_max_width, $lightbox_max_height, $responsive_settings, $row_height_mode, $row_height, $max_viewport_height, $single_image_alignment) {

    // Get admin breakpoints
    $breakpoints = [];
    if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_breakpoints')) {
        $breakpoints = FlickrJustifiedAdminSettings::get_breakpoints();
    }

    // Get attribution mode and builtin lightbox setting for frontend
    $attribution_mode = FlickrJustifiedAdminSettings::get_flickr_attribution_mode();
    $use_builtin_lightbox = FlickrJustifiedAdminSettings::get_use_builtin_lightbox();

    // Generate simple structure - JavaScript will organize into responsive rows
    $output = sprintf(
        '<div id="%s" class="flickr-justified-grid" style="--gap: %dpx;" data-responsive-settings="%s" data-breakpoints="%s" data-row-height-mode="%s" data-row-height="%d" data-max-viewport-height="%d" data-single-image-alignment="%s" data-attribution-mode="%s" data-use-builtin-lightbox="%s">',
        esc_attr($block_id),
        (int) $gap,
        esc_attr(json_encode($responsive_settings)),
        esc_attr(json_encode($breakpoints)),
        esc_attr($row_height_mode),
        (int) $row_height,
        (int) $max_viewport_height,
        esc_attr($single_image_alignment),
        esc_attr($attribution_mode),
        $use_builtin_lightbox ? '1' : '0'
    );

    foreach ($url_lines as $url) {
        $url = esc_url($url);
        if (empty($url)) continue;

        $is_flickr = strpos($url, 'flickr.com/photos/') !== false;

        if ($is_flickr) {
            $available_sizes = [
                'original', 'large2048', 'large1600', 'large1024', 'large',
                'medium800', 'medium640', 'medium500', 'medium'
            ];
            $image_data = flickr_justified_get_flickr_image_sizes_with_dimensions($url, $available_sizes);

            $display_src = isset($image_data[$image_size]['url']) ? $image_data[$image_size]['url'] : '';
            $dimensions = isset($image_data[$image_size]) ? $image_data[$image_size] : null;

            // Use different sizing strategy for built-in lightbox
            if ($use_builtin_lightbox) {
                // For PhotoSwipe, use larger sizes appropriate for typical screen sizes
                // Most screens are 1920x1080 to 2560x1440, so use generous limits
                $best_lightbox_size = flickr_justified_select_best_size($image_data, 2560, 1600);
            } else {
                // Use user's lightbox settings for third-party lightboxes
                $best_lightbox_size = flickr_justified_select_best_size($image_data, $lightbox_max_width, $lightbox_max_height);
            }

            $lightbox_src = '';
            if ($best_lightbox_size && isset($image_data[$best_lightbox_size]['url'])) {
                $lightbox_src = $image_data[$best_lightbox_size]['url'];
            }

            if (empty($display_src) && !empty($lightbox_src)) {
                $display_src = $lightbox_src;
                $dimensions = isset($image_data[$best_lightbox_size]) ? $image_data[$best_lightbox_size] : null;
            }
            if (empty($lightbox_src) && !empty($display_src)) {
                $lightbox_src = $display_src;
            }

            // If API failed to get any images from Flickr URLs, handle based on settings
            if (empty($display_src)) {
                $error_mode = FlickrJustifiedAdminSettings::get_privacy_error_mode();

                if ($error_mode === 'show_nothing') {
                    // Return just a line break to prevent blocks from running together
                    return '<br>';
                } else {
                    // Show error message (default behavior)
                    $error_message = FlickrJustifiedAdminSettings::get_custom_error_message();

                    // Convert line breaks to HTML and parse basic HTML
                    $error_message = wp_kses($error_message, [
                        'strong' => [],
                        'em' => [],
                        'br' => [],
                        'p' => [],
                        'span' => ['style' => []],
                        'div' => ['style' => []],
                    ]);
                    $error_message = nl2br($error_message);

                    return '<div class="flickr-justified-error" style="
                        padding: 20px;
                        background: #f8d7da;
                        border: 1px solid #f5c6cb;
                        border-radius: 4px;
                        color: #721c24;
                        text-align: center;
                        margin: 20px 0;
                    ">' . $error_message . '</div>';
                }
            }

            if (!empty($display_src)) {
                $data_attrs = '';
                if ($dimensions) {
                    $data_attrs = sprintf('data-width="%d" data-height="%d"', $dimensions['width'], $dimensions['height']);
                }

                // Use different lightbox settings based on builtin lightbox preference
                if ($use_builtin_lightbox) {
                    $lightbox_class = 'flickr-builtin-lightbox';
                    $gallery_group_attribute = 'data-gallery';
                    $gallery_group = esc_attr($block_id);
                } else {
                    $lightbox_class = FlickrJustifiedAdminSettings::get_lightbox_css_class();
                    $gallery_group_attribute = FlickrJustifiedAdminSettings::get_gallery_group_attribute();
                    $gallery_group_format = FlickrJustifiedAdminSettings::get_gallery_group_format();
                    $gallery_group = str_replace('{block_id}', esc_attr($block_id), $gallery_group_format);
                }

                // Get attribution settings
                $attribution_mode = FlickrJustifiedAdminSettings::get_flickr_attribution_mode();
                $attribution_text = FlickrJustifiedAdminSettings::get_attribution_text();
                $attribution_position = FlickrJustifiedAdminSettings::get_attribution_position();

                // Build attribution data attributes for lightbox plugins
                $attribution_attrs = '';
                if ($attribution_mode !== 'disabled') {
                    $attribution_attrs = sprintf(' data-flickr-page="%s" data-flickr-attribution-text="%s"',
                        esc_attr($url),
                        esc_attr($attribution_text)
                    );

                    // Add data attributes for common lightbox caption methods
                    $attribution_attrs .= sprintf(' data-caption="%s" data-title="%s" title="%s"',
                        esc_attr($attribution_text),
                        esc_attr($attribution_text),
                        esc_attr($attribution_text)
                    );
                }

                // Build caption overlay if needed
                $caption_overlay = '';
                if ($attribution_mode === 'caption_overlay') {
                    $position_classes = [
                        'bottom_left' => 'bottom: 8px; left: 8px;',
                        'bottom_right' => 'bottom: 8px; right: 8px;',
                        'bottom_center' => 'bottom: 8px; left: 50%; transform: translateX(-50%);',
                        'top_left' => 'top: 8px; left: 8px;',
                        'top_right' => 'top: 8px; right: 8px;'
                    ];

                    $position_style = isset($position_classes[$attribution_position])
                        ? $position_classes[$attribution_position]
                        : $position_classes['bottom_right'];

                    $caption_overlay = sprintf(
                        '<div class="flickr-attribution-overlay" style="
                            position: absolute;
                            %s
                            background: rgba(0,0,0,0.7);
                            color: white;
                            padding: 4px 8px;
                            border-radius: 3px;
                            font-size: 12px;
                            z-index: 10;
                            pointer-events: none;
                        ">
                            <a href="%s" target="_blank" rel="noopener" style="color: white; text-decoration: none; pointer-events: auto;">%s</a>
                        </div>',
                        $position_style,
                        esc_attr($url),
                        esc_html($attribution_text)
                    );
                }

                $output .= sprintf(
                    '<article class="flickr-card" %s style="position: relative;">
                        <a href="%s" class="%s" %s="%s"%s>
                            <img src="%s" loading="lazy" decoding="async" alt="">
                        </a>%s
                    </article>',
                    $data_attrs,
                    esc_url($lightbox_src),
                    esc_attr($lightbox_class),
                    esc_attr($gallery_group_attribute),
                    esc_attr($gallery_group),
                    $attribution_attrs,
                    esc_url($display_src),
                    $caption_overlay
                );
            }
        } else {
            // Direct image URL
            $lightbox_class = FlickrJustifiedAdminSettings::get_lightbox_css_class();
            $gallery_group_attribute = FlickrJustifiedAdminSettings::get_gallery_group_attribute();
            $gallery_group_format = FlickrJustifiedAdminSettings::get_gallery_group_format();
            $gallery_group = str_replace('{block_id}', esc_attr($block_id), $gallery_group_format);
            $output .= sprintf(
                '<article class="flickr-card">
                    <a href="%s" class="%s" %s="%s">
                        <img src="%s" loading="lazy" decoding="async" alt="">
                    </a>
                </article>',
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
 * Render the Flickr Justified block
 *
 * @param array $attributes Block attributes
 * @return string Block HTML output
 */
function flickr_justified_render_block($attributes) {
    $urls = isset($attributes['urls']) ? trim($attributes['urls']) : '';
    $gap = isset($attributes['gap']) ? max(0, (int) $attributes['gap']) : 12;
    $image_size = isset($attributes['imageSize']) ? $attributes['imageSize'] : 'large';
    $lightbox_max_width = isset($attributes['lightboxMaxWidth']) ? max(400, (int) $attributes['lightboxMaxWidth']) : 2048;
    $lightbox_max_height = isset($attributes['lightboxMaxHeight']) ? max(300, (int) $attributes['lightboxMaxHeight']) : 2048;
    $responsive_settings = isset($attributes['responsiveSettings']) ? $attributes['responsiveSettings'] : [
        'mobile' => 1,
        'mobile_landscape' => 1,
        'tablet_portrait' => 2,
        'tablet_landscape' => 3,
        'desktop' => 3,
        'large_desktop' => 4,
        'extra_large' => 4
    ];
    $row_height_mode = isset($attributes['rowHeightMode']) ? $attributes['rowHeightMode'] : 'auto';
    $row_height = isset($attributes['rowHeight']) ? max(120, min(500, (int) $attributes['rowHeight'])) : 280;
    $max_viewport_height = isset($attributes['maxViewportHeight']) ? max(30, min(100, (int) $attributes['maxViewportHeight'])) : 80;
    $single_image_alignment = isset($attributes['singleImageAlignment']) ? $attributes['singleImageAlignment'] : 'center';

    if (empty($urls)) {
        return '';
    }

    // Split URLs by lines and clean them
    $url_lines = array_filter(array_map('trim', preg_split('/\R/u', $urls)));

    if (empty($url_lines)) {
        return '';
    }

    // Generate unique ID for this block instance
    $block_id = 'flickr-justified-' . uniqid();

    // Use justified gallery layout
    return flickr_justified_render_justified_gallery(
        $url_lines, $block_id, $gap, $image_size, $lightbox_max_width, $lightbox_max_height, $responsive_settings, $row_height_mode, $row_height, $max_viewport_height, $single_image_alignment
    );
}
