<?php
/**
 * Plugin Name: Flickr Justified Block
 * Plugin URI: https://github.com/radialmonster/flickr-justified-block
 * Description: A WordPress block that displays Flickr photos and other images in a responsive justified gallery layout. Simply paste URLs (one per line) and configure columns and spacing.
 * Version: 1.1.0
 * Author: RadialMonster
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: flickr-justified-block
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * GitHub Plugin URI: radialmonster/flickr-justified-block
 * Primary Branch: main
 *
 * @package FlickrJustifiedBlock
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('FLICKR_JUSTIFIED_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FLICKR_JUSTIFIED_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FLICKR_JUSTIFIED_VERSION', '1.1.0');

/**
 * Main plugin class
 */
class FlickrJustifiedBlock {

    /**
     * Initialize the plugin
     */
    public static function init() {
        add_action('init', [__CLASS__, 'register_block']);
        add_action('enqueue_block_editor_assets', [__CLASS__, 'enqueue_editor_assets']);
        add_action('enqueue_block_assets', [__CLASS__, 'enqueue_block_assets']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_filter('block_type_metadata', [__CLASS__, 'modify_block_attributes'], 10, 1);
    }

    /**
     * Register the block
     */
    public static function register_block() {
        if (function_exists('register_block_type_from_metadata')) {
            register_block_type_from_metadata(
                FLICKR_JUSTIFIED_PLUGIN_PATH,
                [ 'render_callback' => 'flickr_justified_render_block' ]
            );
            return;
        }

        if (!function_exists('register_block_type')) {
            return;
        }

        // Fallback registration for older WP without metadata support
        register_block_type('flickr-justified/block', [
            'render_callback' => 'flickr_justified_render_block'
        ]);
    }

    /**
     * Modify block attributes to use configured defaults
     */
    public static function modify_block_attributes($metadata) {
        if (isset($metadata['name']) && $metadata['name'] === 'flickr-justified/block') {
            // Get configured default responsive settings from admin
            $configured_defaults = [];
            if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_configured_default_responsive_settings')) {
                $configured_defaults = FlickrJustifiedAdminSettings::get_configured_default_responsive_settings();
            } else {
                // Fallback to hardcoded defaults if admin class not loaded
                $configured_defaults = [
                    'mobile' => 1,
                    'mobile_landscape' => 1,
                    'tablet_portrait' => 2,
                    'tablet_landscape' => 3,
                    'desktop' => 3,
                    'large_desktop' => 4,
                    'extra_large' => 4
                ];
            }

            // Update the default value for responsiveSettings
            if (isset($metadata['attributes']['responsiveSettings'])) {
                $metadata['attributes']['responsiveSettings']['default'] = $configured_defaults;
            }
        }
        return $metadata;
    }

    /**
     * Enqueue editor assets
     */
    public static function enqueue_editor_assets() {
        $editor_js_path = FLICKR_JUSTIFIED_PLUGIN_PATH . 'assets/js/editor.js';
        $editor_js_ver  = file_exists($editor_js_path) ? filemtime($editor_js_path) : false;
        wp_enqueue_script(
            'flickr-justified-editor',
            FLICKR_JUSTIFIED_PLUGIN_URL . 'assets/js/editor.js',
            ['wp-blocks', 'wp-components', 'wp-element', 'wp-block-editor', 'wp-i18n', 'wp-api-fetch'],
            $editor_js_ver ? $editor_js_ver : FLICKR_JUSTIFIED_VERSION,
            true
        );
    }

    /**
     * Enqueue block assets (both editor and frontend)
     */
    public static function enqueue_block_assets() {
        $style_path = FLICKR_JUSTIFIED_PLUGIN_PATH . 'assets/css/style.css';
        $style_ver  = file_exists($style_path) ? filemtime($style_path) : false;

        // If metadata registration is unavailable, enqueue style manually
        if (!function_exists('register_block_type_from_metadata')) {
            wp_enqueue_style(
                'flickr-justified-style',
                FLICKR_JUSTIFIED_PLUGIN_URL . 'assets/css/style.css',
                ['wp-block-library'],
                $style_ver ? $style_ver : FLICKR_JUSTIFIED_VERSION
            );
        }

        // Only enqueue JavaScript on frontend
        if (!is_admin()) {
            // Always use built-in PhotoSwipe lightbox
            $photoswipe_js_path = FLICKR_JUSTIFIED_PLUGIN_PATH . 'assets/js/photoswipe-init.js';
            $photoswipe_js_ver  = file_exists($photoswipe_js_path) ? filemtime($photoswipe_js_path) : false;

            wp_enqueue_script(
                'flickr-justified-photoswipe',
                FLICKR_JUSTIFIED_PLUGIN_URL . 'assets/js/photoswipe-init.js',
                [],
                $photoswipe_js_ver ? $photoswipe_js_ver : FLICKR_JUSTIFIED_VERSION,
                true
            );

            // Pass plugin URL to JavaScript
            wp_localize_script('flickr-justified-photoswipe', 'flickrJustifiedConfig', [
                'pluginUrl' => FLICKR_JUSTIFIED_PLUGIN_URL
            ]);

            // Initialize justified layout script
            $init_js_path = FLICKR_JUSTIFIED_PLUGIN_PATH . 'assets/js/justified-init.js';
            $init_js_ver  = file_exists($init_js_path) ? filemtime($init_js_path) : false;

            wp_enqueue_script(
                'flickr-justified-layout',
                FLICKR_JUSTIFIED_PLUGIN_URL . 'assets/js/justified-init.js',
                [],
                $init_js_ver ? $init_js_ver : FLICKR_JUSTIFIED_VERSION,
                true
            );
        }
    }

    /**
     * Register REST API routes for editor preview
     */
    public static function register_rest_routes() {
        register_rest_route('flickr-justified/v1', '/preview-image', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'get_image_preview'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => [
                'url' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw'
                ]
            ]
        ]);

        register_rest_route('flickr-justified/v1', '/load-album-page', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'load_album_page'],
            'permission_callback' => '__return_true', // Public endpoint for frontend lazy loading
            'args' => [
                'user_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'photoset_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'page' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                ]
            ]
        ]);
    }

    /**
     * Get image preview data for editor
     */
    public static function get_image_preview($request) {
        $url = $request->get_param('url');

        if (empty($url)) {
            return new WP_Error('invalid_url', 'Invalid URL provided', ['status' => 400]);
        }

        $is_flickr = (strpos($url, 'flickr.com/photos/') !== false || strpos($url, 'www.flickr.com/photos/') !== false);

        // Check if this is a Flickr set/album URL
        if (!function_exists('flickr_justified_parse_set_url')) {
            return new WP_Error('function_missing', 'Required function not available', ['status' => 500]);
        }

        $set_info = flickr_justified_parse_set_url($url);
        if ($set_info) {
            // This is a Flickr set - get the first photo for preview
            if (!function_exists('flickr_justified_get_photoset_photos')) {
                return new WP_Error('function_missing', 'Required function not available', ['status' => 500]);
            }

            $set_photos = flickr_justified_get_photoset_photos($set_info['user_id'], $set_info['photoset_id'], $url);
            if (!empty($set_photos)) {
                return [
                    'success' => true,
                    'image_url' => '', // We'll show a set indicator instead
                    'is_flickr' => true,
                    'is_set' => true,
                    'set_info' => [
                        'user_id' => sanitize_text_field($set_info['user_id']),
                        'photoset_id' => sanitize_text_field($set_info['photoset_id'])
                    ],
                    'photo_count' => count($set_photos),
                    'first_photo' => !empty($set_photos[0]) ? esc_url_raw($set_photos[0]) : ''
                ];
            } else {
                return new WP_Error('set_error', 'Could not fetch Flickr set data', ['status' => 404]);
            }
        }

        if ($is_flickr) {
            // Get Flickr image data
            if (!function_exists('flickr_justified_get_flickr_image_sizes_with_dimensions')) {
                return new WP_Error('function_missing', 'Required function not available', ['status' => 500]);
            }

            $available_sizes = ['medium', 'large', 'large1600', 'original'];
            $image_data = flickr_justified_get_flickr_image_sizes_with_dimensions($url, $available_sizes);

            if (empty($image_data)) {
                return new WP_Error('flickr_error', 'Could not fetch Flickr image data', ['status' => 404]);
            }

            // Use medium size for editor preview
            $preview_size = 'medium';
            if (!isset($image_data[$preview_size]) && isset($image_data['large'])) {
                $preview_size = 'large';
            } elseif (!isset($image_data[$preview_size]) && !isset($image_data['large'])) {
                $first_available = array_key_first($image_data);
                if ($first_available !== null) {
                    $preview_size = $first_available;
                } else {
                    return new WP_Error('no_sizes', 'No image sizes available', ['status' => 404]);
                }
            }

            if (isset($image_data[$preview_size]) && isset($image_data[$preview_size]['url'])) {
                return [
                    'success' => true,
                    'image_url' => $image_data[$preview_size]['url'],
                    'width' => $image_data[$preview_size]['width'],
                    'height' => $image_data[$preview_size]['height'],
                    'is_flickr' => true
                ];
            }
        } else {
            // For direct image URLs, just return the URL
            $is_image_url = preg_match('/\.(jpe?g|png|webp|avif|gif|svg)(\?|#|$)/i', $url);
            if ($is_image_url) {
                return [
                    'success' => true,
                    'image_url' => $url,
                    'is_flickr' => false
                ];
            }
        }

        return new WP_Error('unsupported_url', 'Unsupported URL type', ['status' => 400]);
    }

    /**
     * Load additional page from Flickr album/set for lazy loading
     */
    public static function load_album_page($request) {
        // Simple rate limiting: limit to 10 requests per minute per IP
        $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rate_limit_key = 'flickr_lazy_load_' . md5($client_ip);
        $current_requests = get_transient($rate_limit_key) ?: 0;

        if ($current_requests >= 10) {
            return new WP_Error('rate_limit_exceeded', 'Too many requests. Please wait a moment.', ['status' => 429]);
        }

        // Increment request counter
        set_transient($rate_limit_key, $current_requests + 1, 60); // 60 seconds

        $user_id = $request->get_param('user_id');
        $photoset_id = $request->get_param('photoset_id');
        $page = $request->get_param('page');

        if (empty($user_id) || empty($photoset_id) || $page < 1) {
            return new WP_Error('invalid_params', 'Invalid parameters provided', ['status' => 400]);
        }

        // Check if required functions are available
        if (!function_exists('flickr_justified_get_photoset_photos_paginated')) {
            return new WP_Error('function_missing', 'Required function not available', ['status' => 500]);
        }

        // Get the photos for this page
        $result = flickr_justified_get_photoset_photos_paginated($user_id, $photoset_id, $page, 500);

        if (!is_array($result) || empty($result['photos'])) {
            return new WP_Error('no_photos', 'No photos found for this page', ['status' => 404]);
        }

        // Return the photos as gallery HTML items
        $gallery_items = [];
        foreach ($result['photos'] as $photo_url) {
            $photo_url = esc_url($photo_url);
            if (empty($photo_url)) continue;

            $is_flickr = (strpos($photo_url, 'flickr.com/photos/') !== false || strpos($photo_url, 'www.flickr.com/photos/') !== false);

            if ($is_flickr) {
                // Get image data for this photo
                if (!function_exists('flickr_justified_get_flickr_image_sizes_with_dimensions')) {
                    continue;
                }

                $available_sizes = [
                    'original', 'large6k', 'large5k', 'largef', 'large4k', 'large3k',
                    'large2048', 'large1600', 'large1024', 'large',
                    'medium800', 'medium640', 'medium500', 'medium',
                    'small400', 'small320', 'small240',
                ];
                $image_data = flickr_justified_get_flickr_image_sizes_with_dimensions($photo_url, $available_sizes);

                if (!empty($image_data)) {
                    $preferred_size = 'large';
                    if (isset($image_data[$preferred_size]) && isset($image_data[$preferred_size]['url'])) {
                        $gallery_items[] = [
                            'url' => $photo_url,
                            'image_url' => $image_data[$preferred_size]['url'],
                            'width' => $image_data[$preferred_size]['width'],
                            'height' => $image_data[$preferred_size]['height'],
                            'flickr_page' => $photo_url,
                            'is_flickr' => true
                        ];
                    } else {
                        // Fallback: use first available size
                        $first_size = array_keys($image_data)[0] ?? null;
                        if ($first_size && isset($image_data[$first_size]['url'])) {
                            $gallery_items[] = [
                                'url' => $photo_url,
                                'image_url' => $image_data[$first_size]['url'],
                                'width' => $image_data[$first_size]['width'] ?? 0,
                                'height' => $image_data[$first_size]['height'] ?? 0,
                                'flickr_page' => $photo_url,
                                'is_flickr' => true
                            ];
                        }
                    }
                } else {
                    // Fallback: use original photo URL (might not work but better than skipping)
                    $gallery_items[] = [
                        'url' => $photo_url,
                        'image_url' => $photo_url,
                        'flickr_page' => $photo_url,
                        'is_flickr' => true
                    ];
                }
            } else {
                // Direct image URL
                $gallery_items[] = [
                    'url' => $photo_url,
                    'image_url' => $photo_url,
                    'is_flickr' => false
                ];
            }
        }

        return [
            'success' => true,
            'photos' => $gallery_items,
            'page' => $result['page'],
            'has_more' => $result['has_more'],
            'total_pages' => $result['pages'],
            'total_photos' => $result['total']
        ];
    }
}

// Include required files first
require_once FLICKR_JUSTIFIED_PLUGIN_PATH . 'includes/render.php';
require_once FLICKR_JUSTIFIED_PLUGIN_PATH . 'includes/admin-settings.php';

// Initialize the plugin after includes are loaded
FlickrJustifiedBlock::init();
