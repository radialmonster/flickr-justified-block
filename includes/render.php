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
        error_log('Flickr Justified Block: No API key found for photo ID: ' . $photo_id);
        return [];
    }

    // Debug: Log that we're making an API call
    error_log('Flickr Justified Block: Making API call for photo ID: ' . $photo_id);

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
        'user-agent' => 'WordPress Flickr Masonry Block'
    ]);

    if (is_wp_error($response)) {
        error_log('Flickr Justified Block: API request error for photo ID ' . $photo_id . ': ' . $response->get_error_message());
        return [];
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (empty($data['sizes']['size'])) {
        error_log('Flickr Justified Block: No sizes data returned for photo ID ' . $photo_id . '. Response: ' . $body);
        return [];
    }

    // Debug: Log successful API call
    error_log('Flickr Justified Block: Successfully retrieved ' . count($data['sizes']['size']) . ' sizes for photo ID: ' . $photo_id);

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
function flickr_justified_render_justified_gallery($url_lines, $block_id, $gap, $image_size, $lightbox_max_width, $lightbox_max_height, $responsive_settings, $row_height_mode, $row_height) {

    // Get admin breakpoints
    $breakpoints = [];
    if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_breakpoints')) {
        $breakpoints = FlickrJustifiedAdminSettings::get_breakpoints();
    }

    // Generate simple structure - JavaScript will organize into responsive rows
    $output = sprintf(
        '<div id="%s" class="flickr-justified-grid" style="--gap: %dpx;" data-responsive-settings="%s" data-breakpoints="%s" data-row-height-mode="%s" data-row-height="%d">',
        esc_attr($block_id),
        (int) $gap,
        esc_attr(json_encode($responsive_settings)),
        esc_attr(json_encode($breakpoints)),
        esc_attr($row_height_mode),
        (int) $row_height
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

            $best_lightbox_size = flickr_justified_select_best_size($image_data, $lightbox_max_width, $lightbox_max_height);
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

            // If API failed to get any images from Flickr URLs, show error message
            if (empty($display_src)) {
                return '<div class="flickr-justified-error" style="
                    padding: 20px;
                    background: #f8d7da;
                    border: 1px solid #f5c6cb;
                    border-radius: 4px;
                    color: #721c24;
                    text-align: center;
                    margin: 20px 0;
                ">
                    <h4 style="margin: 0 0 10px 0;">Gallery not available</h4>
                    <p style="margin: 0;">Please check your Flickr API key in the plugin settings.</p>
                </div>';
            }

            if (!empty($display_src)) {
                $data_attrs = '';
                if ($dimensions) {
                    $data_attrs = sprintf('data-width="%d" data-height="%d"', $dimensions['width'], $dimensions['height']);
                }

                $output .= sprintf(
                    '<article class="flickr-card" %s>
                        <a href="%s" class="flickr-justified-item" data-flickr-page="%s">
                            <img src="%s" loading="lazy" decoding="async" alt="">
                        </a>
                    </article>',
                    $data_attrs,
                    esc_url($lightbox_src),
                    esc_attr($url),
                    esc_url($display_src)
                );
            }
        } else {
            // Direct image URL
            $output .= sprintf(
                '<article class="flickr-card">
                    <a href="%s" class="flickr-justified-item">
                        <img src="%s" loading="lazy" decoding="async" alt="">
                    </a>
                </article>',
                esc_url($url),
                esc_url($url)
            );
        }
    }

    $output .= '</div>';
    return $output;
}

/**
 * Render the Flickr Masonry block
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
        $url_lines, $block_id, $gap, $image_size, $lightbox_max_width, $lightbox_max_height, $responsive_settings, $row_height_mode, $row_height
    );
}
