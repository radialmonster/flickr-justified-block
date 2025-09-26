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
     * Base path used when looking up asset files relative to the plugin directory.
     */
    private const ASSET_BASE_PATH = '';

    /**
     * Retrieve details about an asset file located within the plugin directory.
     *
     * @param string $relative_path Relative path from the plugin root.
     *
     * @return array|false Array containing path, url and version or false when missing.
     */
    private static function get_asset_file_details($relative_path) {
        $relative_path = ltrim($relative_path, '/');
        $absolute_path = FLICKR_JUSTIFIED_PLUGIN_PATH . self::ASSET_BASE_PATH . $relative_path;

        if (!file_exists($absolute_path)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('Flickr Justified Block: Asset not found at %s', $absolute_path));
            }
            return false;
        }

        $version = filemtime($absolute_path);
        if (!$version) {
            $version = FLICKR_JUSTIFIED_VERSION;
        }

        return [
            'path'    => $absolute_path,
            'url'     => FLICKR_JUSTIFIED_PLUGIN_URL . self::ASSET_BASE_PATH . $relative_path,
            'version' => $version,
        ];
    }

    /**
     * Retrieve script dependencies/version information, supporting WordPress build asset files.
     *
     * @param string $relative_path Relative path from the plugin root to the script.
     * @param array  $fallback_deps Dependencies to use when no asset file is present.
     *
     * @return array|false Array with url, deps and version or false if the script cannot be located.
     */
    private static function get_script_registration_data($relative_path, array $fallback_deps = []) {
        $asset_details = self::get_asset_file_details($relative_path);
        if (!$asset_details) {
            return false;
        }

        $deps     = $fallback_deps;
        $version  = $asset_details['version'];
        $asset_php_path = preg_replace('/\.js$/', '.asset.php', $asset_details['path']);

        if ($asset_php_path && file_exists($asset_php_path)) {
            $asset_data = include $asset_php_path;
            if (is_array($asset_data)) {
                if (!empty($asset_data['dependencies']) && is_array($asset_data['dependencies'])) {
                    $deps = $asset_data['dependencies'];
                }
                if (!empty($asset_data['version'])) {
                    $version = $asset_data['version'];
                }
            }
        }

        return [
            'url'     => $asset_details['url'],
            'deps'    => $deps,
            'version' => $version,
        ];
    }

    /**
     * Determine whether the provided URL points to Flickr.
     *
     * @param string $url URL to inspect.
     *
     * @return bool
     */
    private static function is_flickr_url($url) {
        if (empty($url)) {
            return false;
        }

        return (bool) preg_match('~(?:https?:)?//(?:www\.)?flickr\.com/photos/~i', $url);
    }

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
        $script_data = self::get_script_registration_data(
            'assets/js/editor.js',
            ['wp-blocks', 'wp-components', 'wp-element', 'wp-block-editor', 'wp-i18n', 'wp-api-fetch']
        );

        if (!$script_data) {
            return;
        }

        wp_register_script(
            'flickr-justified-editor',
            $script_data['url'],
            $script_data['deps'],
            $script_data['version'],
            true
        );

        wp_enqueue_script('flickr-justified-editor');

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                'flickr-justified-editor',
                'flickr-justified-block',
                FLICKR_JUSTIFIED_PLUGIN_PATH . 'languages'
            );
        }
    }

    /**
     * Enqueue block assets (both editor and frontend)
     */
    public static function enqueue_block_assets() {
        $style_details = self::get_asset_file_details('assets/css/style.css');
        $style_ver     = $style_details ? $style_details['version'] : false;

        // If metadata registration is unavailable, enqueue style manually
        if (!function_exists('register_block_type_from_metadata')) {
            wp_enqueue_style(
                'flickr-justified-style',
                $style_details ? $style_details['url'] : FLICKR_JUSTIFIED_PLUGIN_URL . 'assets/css/style.css',
                ['wp-block-library'],
                $style_ver ? $style_ver : FLICKR_JUSTIFIED_VERSION
            );
        }

        // Only enqueue JavaScript on frontend
        if (!is_admin()) {
            // Always use built-in PhotoSwipe lightbox
            $photoswipe_script = self::get_script_registration_data('assets/js/photoswipe-init.js');
            if ($photoswipe_script) {
                wp_enqueue_script(
                    'flickr-justified-photoswipe',
                    $photoswipe_script['url'],
                    $photoswipe_script['deps'],
                    $photoswipe_script['version'],
                    true
                );

                // Pass plugin URL to JavaScript
                wp_localize_script('flickr-justified-photoswipe', 'flickrJustifiedConfig', [
                    'pluginUrl' => FLICKR_JUSTIFIED_PLUGIN_URL
                ]);
            }

            // Initialize justified layout script
            $layout_script = self::get_script_registration_data('assets/js/justified-init.js');
            if ($layout_script) {
                wp_enqueue_script(
                    'flickr-justified-layout',
                    $layout_script['url'],
                    $layout_script['deps'],
                    $layout_script['version'],
                    true
                );
            }
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

        $is_flickr = self::is_flickr_url($url);

        // Check if this is a Flickr set/album URL
        if (!function_exists('flickr_justified_parse_set_url')) {
            return new WP_Error('function_missing', 'Required function not available', ['status' => 500]);
        }

        $set_info = flickr_justified_parse_set_url($url);
        if ($set_info) {
            // This is a Flickr set - get the photos and metadata for preview
            if (!function_exists('flickr_justified_get_photoset_photos_paginated')) {
                return new WP_Error('function_missing', 'Required function not available', ['status' => 500]);
            }

            $set_result = flickr_justified_get_photoset_photos_paginated($set_info['user_id'], $set_info['photoset_id'], 1, 50);
            if (!empty($set_result['photos'])) {
                $response_data = [
                    'success' => true,
                    'image_url' => '', // We'll show a set indicator instead
                    'is_flickr' => true,
                    'is_set' => true,
                    'set_info' => [
                        'user_id' => sanitize_text_field($set_info['user_id']),
                        'photoset_id' => sanitize_text_field($set_info['photoset_id'])
                    ],
                    'photo_count' => count($set_result['photos']),
                    'album_title' => !empty($set_result['album_title']) ? sanitize_text_field($set_result['album_title']) : '',
                    'first_photo' => !empty($set_result['photos'][0]) ? esc_url_raw($set_result['photos'][0]) : ''
                ];

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Flickr Justified Block: REST API returning album data: ' . json_encode($response_data));
                }

                return rest_ensure_response($response_data);
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
                return rest_ensure_response([
                    'success' => true,
                    'image_url' => esc_url_raw($image_data[$preview_size]['url']),
                    'width' => isset($image_data[$preview_size]['width']) ? absint($image_data[$preview_size]['width']) : 0,
                    'height' => isset($image_data[$preview_size]['height']) ? absint($image_data[$preview_size]['height']) : 0,
                    'is_flickr' => true
                ]);
            }
        } else {
            // For direct image URLs, just return the URL
            $is_image_url = preg_match('/\.(jpe?g|png|webp|avif|gif|svg)(\?|#|$)/i', $url);
            if ($is_image_url) {
                return rest_ensure_response([
                    'success' => true,
                    'image_url' => esc_url_raw($url),
                    'is_flickr' => false
                ]);
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
        $result = flickr_justified_get_photoset_photos_paginated($user_id, $photoset_id, $page, 50);

        if (!is_array($result) || empty($result['photos'])) {
            return new WP_Error('no_photos', 'No photos found for this page', ['status' => 404]);
        }

        // Return the photos as gallery HTML items
        $gallery_items = [];
        foreach ($result['photos'] as $photo_url) {
            $photo_url = esc_url_raw($photo_url);
            if (empty($photo_url)) continue;

            $is_flickr = self::is_flickr_url($photo_url);

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
                            'image_url' => esc_url_raw($image_data[$preferred_size]['url']),
                            'width' => isset($image_data[$preferred_size]['width']) ? absint($image_data[$preferred_size]['width']) : 0,
                            'height' => isset($image_data[$preferred_size]['height']) ? absint($image_data[$preferred_size]['height']) : 0,
                            'flickr_page' => $photo_url,
                            'is_flickr' => true
                        ];
                    } else {
                        // Fallback: use first available size
                        $first_size = array_keys($image_data)[0] ?? null;
                        if ($first_size && isset($image_data[$first_size]['url'])) {
                            $gallery_items[] = [
                                'url' => $photo_url,
                                'image_url' => esc_url_raw($image_data[$first_size]['url']),
                                'width' => isset($image_data[$first_size]['width']) ? absint($image_data[$first_size]['width']) : 0,
                                'height' => isset($image_data[$first_size]['height']) ? absint($image_data[$first_size]['height']) : 0,
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

        return rest_ensure_response([
            'success' => true,
            'photos' => $gallery_items,
            'page' => $result['page'],
            'has_more' => $result['has_more'],
            'total_pages' => $result['pages'],
            'total_photos' => $result['total']
        ]);
    }
}

// Include required files first
require_once FLICKR_JUSTIFIED_PLUGIN_PATH . 'includes/render.php';
require_once FLICKR_JUSTIFIED_PLUGIN_PATH . 'includes/admin-settings.php';

// Initialize the plugin after includes are loaded
FlickrJustifiedBlock::init();
