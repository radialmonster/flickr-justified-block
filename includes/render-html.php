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
 * Derive human-friendly text for alt/caption/title attributes.
 *
 * @param array $photo Raw photo array from the renderer.
 * @param array $image_data Cached Flickr size/info payload.
 * @param bool  $is_flickr Whether the photo is from Flickr.
 * @return array{title:string,caption:string,alt:string}
 */
function flickr_justified_get_photo_text($photo, $image_data, $is_flickr = true) {
    $title = '';

    if (isset($photo['title']) && is_string($photo['title']) && '' !== trim($photo['title'])) {
        $title = sanitize_text_field($photo['title']);
    } elseif (isset($image_data['_photo_info']['title'])) {
        $raw_title = $image_data['_photo_info']['title'];
        if (is_array($raw_title) && isset($raw_title['_content'])) {
            $raw_title = $raw_title['_content'];
        }
        if (is_string($raw_title) && '' !== trim($raw_title)) {
            $title = sanitize_text_field($raw_title);
        }
    }

    $fallback = $is_flickr ? 'Flickr photo' : 'Image';
    $caption = '' !== $title ? $title : $fallback;
    $alt = $caption;

    return [
        'title' => $title,
        'caption' => $caption,
        'alt' => $alt,
    ];
}

/**
 * Build srcset/sizes attributes from cached Flickr size data.
 *
 * @param array $image_data Cached sizes/metadata.
 * @param array $available_sizes Size keys in preferred order.
 * @return array{0:string,1:string} [srcset, sizes]
 */
function flickr_justified_build_srcset_attributes($image_data, $available_sizes) {
    if (empty($image_data) || !is_array($image_data)) {
        return ['', ''];
    }

    $srcset_entries = [];

    foreach ($available_sizes as $size_key) {
        if (!isset($image_data[$size_key]['url'])) {
            continue;
        }

        $width = isset($image_data[$size_key]['width']) ? (int) $image_data[$size_key]['width'] : 0;
        $url = esc_url($image_data[$size_key]['url']);

        if ($width <= 0 || '' === $url) {
            continue;
        }

        // Use width as key to dedupe any duplicate entries
        $srcset_entries[$width] = $url;
    }

    if (empty($srcset_entries)) {
        return ['', ''];
    }

    ksort($srcset_entries);

    $srcset_parts = [];
    foreach ($srcset_entries as $width => $src) {
        $srcset_parts[] = $src . ' ' . (int) $width . 'w';
    }

    $srcset = implode(', ', $srcset_parts);
    $sizes = '(max-width: 768px) 100vw, (max-width: 1400px) 90vw, 1400px';

    return [$srcset, $sizes];
}

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
        $photo_id = null;
        if ($is_flickr) {
            if (preg_match('#flickr\.com/photos/[^/]+/(\d+)#', $url, $m)) {
                $photo_id = $m[1];
            }
        }
        $stats = [];

        if ($is_flickr) {
            $attribution_page_url = isset($photo['attribution_url']) ? esc_url($photo['attribution_url']) : $url;
            if ('' === $attribution_page_url) {
                $attribution_page_url = $url;
            }

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

            $photo_text = flickr_justified_get_photo_text($photo, $image_data, true);

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

            $lightbox_class = 'flickr-builtin-lightbox';
            $gallery_group_attribute = 'data-gallery';
            $gallery_group = esc_attr($block_id);

            list($srcset_attr, $sizes_attr) = flickr_justified_build_srcset_attributes($image_data, $available_sizes);

            $attribution_caption = $photo_text['caption'];
            if ('' === $attribution_caption) {
                $attribution_caption = $attribution_text;
            }

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
            if (!empty($photo_id)) {
                $card_attributes[] = 'data-photo-id="' . esc_attr($photo_id) . '"';
            }
            $card_attributes[] = 'data-views="' . esc_attr($views) . '"';
            $card_attributes[] = 'data-comments="' . esc_attr($comments) . '"';
            $card_attributes[] = 'data-favorites="' . esc_attr($favorites) . '"';

            if (null !== $width_attr && null !== $height_attr) {
                $card_attributes[] = 'data-width="' . esc_attr($width_attr) . '"';
                $card_attributes[] = 'data-height="' . esc_attr($height_attr) . '"';
            }

            $img_extra_attrs = [];
            if ($rotation) {
                $img_extra_attrs[] = 'data-rotation="' . esc_attr($rotation) . '"';
                $img_extra_attrs[] = 'style="transform: rotate(' . esc_attr($rotation) . 'deg); transform-origin: center center;"';
            }

            $anchor_attributes = [
                'href="' . esc_url($lightbox_src) . '"',
                'class="' . esc_attr($lightbox_class) . '"',
                $gallery_group_attribute . '="' . esc_attr($gallery_group) . '"',
                'data-flickr-page="' . esc_attr($attribution_page_url) . '"',
                'data-flickr-attribution-text="' . esc_attr($attribution_text) . '"',
                'data-caption="' . esc_attr($attribution_caption) . '"',
                'data-title="' . esc_attr($attribution_caption) . '"',
                'title="' . esc_attr($attribution_caption) . '"',
            ];

            if (null !== $width_attr && null !== $height_attr) {
                $anchor_attributes[] = 'data-width="' . esc_attr($width_attr) . '"';
                $anchor_attributes[] = 'data-height="' . esc_attr($height_attr) . '"';
            }

            if ($rotation) {
                $anchor_attributes[] = 'data-rotation="' . esc_attr($rotation) . '"';
            }

            if (!empty($photo_id)) {
                $anchor_attributes[] = 'data-photo-id="' . esc_attr($photo_id) . '"';
            }

            $img_attributes = [
                'src="' . esc_url($display_src) . '"',
                'loading="lazy"',
                'decoding="async"',
                'alt="' . esc_attr($photo_text['alt']) . '"',
            ];

            if (!empty($srcset_attr)) {
                $img_attributes[] = 'srcset="' . esc_attr($srcset_attr) . '"';
                if (!empty($sizes_attr)) {
                    $img_attributes[] = 'sizes="' . esc_attr($sizes_attr) . '"';
                }
            }

            if (null !== $width_attr && null !== $height_attr) {
                $img_attributes[] = 'width="' . esc_attr($width_attr) . '"';
                $img_attributes[] = 'height="' . esc_attr($height_attr) . '"';
                $img_attributes[] = 'data-width="' . esc_attr($width_attr) . '"';
                $img_attributes[] = 'data-height="' . esc_attr($height_attr) . '"';
            }

            if (!empty($img_extra_attrs)) {
                $img_attributes = array_merge($img_attributes, $img_extra_attrs);
            }

            $img_attr_string = ' ' . implode(' ', $img_attributes);

            $output .= sprintf(
                '<article %s>
                    <a %s>
                        <img%s>
                    </a>
                </article>',
                implode(' ', $card_attributes),
                implode(' ', $anchor_attributes),
                $img_attr_string
            );
        } else {
            // Non-Flickr images
            $views = isset($photo['views']) ? (int) $photo['views'] : 0;
            $comments = isset($photo['comments']) ? (int) $photo['comments'] : 0;
            $favorites = isset($photo['favorites']) ? (int) $photo['favorites'] : 0;
            $photo_text = flickr_justified_get_photo_text($photo, [], false);

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

            $anchor_attributes = [
                'href="' . esc_url($url) . '"',
                'class="' . esc_attr($lightbox_class) . '"',
                $gallery_group_attribute . '="' . esc_attr($gallery_group) . '"',
                'title="' . esc_attr($photo_text['caption']) . '"'
            ];

            if ($width > 0 && $height > 0) {
                $anchor_attributes[] = sprintf('data-width="%d"', $width);
                $anchor_attributes[] = sprintf('data-height="%d"', $height);
            }

            $img_attributes = [
                'src="' . esc_url($url) . '"',
                'loading="lazy"',
                'decoding="async"',
                'alt="' . esc_attr($photo_text['alt']) . '"'
            ];

            if ($width > 0 && $height > 0) {
                $img_attributes[] = sprintf('width="%d"', $width);
                $img_attributes[] = sprintf('height="%d"', $height);
                $img_attributes[] = sprintf('data-width="%d"', $width);
                $img_attributes[] = sprintf('data-height="%d"', $height);
            }

            $output .= sprintf(
                '<article %s>
                    <a %s>
                        <img %s>
                    </a>
                </article>',
                implode(' ', $card_attributes),
                implode(' ', $anchor_attributes),
                implode(' ', $img_attributes)
            );
        }
    }

    $output .= '</div>';
    return $output;
}
