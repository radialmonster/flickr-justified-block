<?php
/**
 * HTML generation functions for Flickr Justified Block
 *
 * Functions that generate the HTML markup for galleries and photo cards.
 *
 * @package FlickrJustifiedBlock
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// HTML GENERATION
// ============================================================================

/**
 * Render photos with justified gallery layout
 *
 * @param array $photos Array of photo data
 * @param string $block_id Unique block identifier
 * @param int $gap Gap between images in pixels
 * @param string $image_size Flickr size to use for display
 * @param array $responsive_settings Responsive breakpoint settings
 * @param string $row_height_mode 'auto' or 'fixed'
 * @param int $row_height Target row height in pixels
 * @param int $max_viewport_height Max row height as percentage of viewport
 * @param string $single_image_alignment Alignment for single images
 * @param array $set_metadata Metadata for lazy-loaded album pages
 * @param array $context Additional context (photo_limit, sort_order)
 * @return string HTML markup for the gallery
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

            // Cache always includes metadata (rotation + stats), so this always returns complete data
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
                    '<article class="flickr-justified-card flickr-error">
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

            $card_attributes = ['class="flickr-justified-card"', 'style="position: relative;"'];
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
            // Non-Flickr images
            $views = isset($photo['views']) ? (int) $photo['views'] : 0;
            $comments = isset($photo['comments']) ? (int) $photo['comments'] : 0;
            $favorites = isset($photo['favorites']) ? (int) $photo['favorites'] : 0;

            $card_attributes = ['class="flickr-justified-card"', 'style="position: relative;"'];
            if (null !== $position) {
                $card_attributes[] = 'data-position="' . esc_attr($position) . '"';
            }
            $card_attributes[] = 'data-views="' . esc_attr($views) . '"';
            $card_attributes[] = 'data-comments="' . esc_attr($comments) . '"';
            $card_attributes[] = 'data-favorites="' . esc_attr($favorites) . '"';

            // Add dimensions if available
            $width = isset($photo['width']) ? (int) $photo['width'] : 0;
            $height = isset($photo['height']) ? (int) $photo['height'] : 0;
            if ($width > 0 && $height > 0) {
                $card_attributes[] = 'data-width="' . esc_attr($width) . '"';
                $card_attributes[] = 'data-height="' . esc_attr($height) . '"';
            }

            $lightbox_class = 'flickr-builtin-lightbox';
            $gallery_group_attribute = 'data-gallery';
            $gallery_group = esc_attr($block_id);

            // Build img and anchor attributes with dimensions
            $data_attrs = '';
            $img_attrs = '';
            if ($width > 0 && $height > 0) {
                $data_attrs = sprintf(' data-width="%d" data-height="%d"', $width, $height);
                $img_attrs = sprintf(' data-width="%d" data-height="%d"', $width, $height);
            }

            $output .= sprintf(
                '<article %s>
                    <a href="%s" class="%s" %s="%s"%s>
                        <img src="%s" loading="lazy" decoding="async" alt=""%s>
                    </a>
                </article>',
                implode(' ', $card_attributes),
                esc_url($url),
                esc_attr($lightbox_class),
                esc_attr($gallery_group_attribute),
                esc_attr($gallery_group),
                $data_attrs,
                esc_url($url),
                $img_attrs
            );
        }
    }

    $output .= '</div>';
    return $output;
}
