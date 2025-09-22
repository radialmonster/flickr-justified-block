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
     * Enqueue editor assets
     */
    public static function enqueue_editor_assets() {
        $editor_js_path = FLICKR_JUSTIFIED_PLUGIN_PATH . 'assets/js/editor.js';
        $editor_js_ver  = @filemtime($editor_js_path);
        wp_enqueue_script(
            'flickr-justified-editor',
            FLICKR_JUSTIFIED_PLUGIN_URL . 'assets/js/editor.js',
            ['wp-blocks', 'wp-components', 'wp-element', 'wp-block-editor', 'wp-i18n'],
            $editor_js_ver ? $editor_js_ver : FLICKR_JUSTIFIED_VERSION,
            true
        );
    }

    /**
     * Enqueue block assets (both editor and frontend)
     */
    public static function enqueue_block_assets() {
        $style_path = FLICKR_JUSTIFIED_PLUGIN_PATH . 'assets/css/style.css';
        $style_ver  = @filemtime($style_path);

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
            $photoswipe_js_ver  = @filemtime($photoswipe_js_path);

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
            $init_js_ver  = @filemtime($init_js_path);

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
    }

    /**
     * Get image preview data for editor
     */
    public static function get_image_preview($request) {
        $url = $request->get_param('url');

        if (empty($url)) {
            return new WP_Error('invalid_url', 'Invalid URL provided', ['status' => 400]);
        }

        $is_flickr = strpos($url, 'flickr.com/photos/') !== false;

        if ($is_flickr) {
            // Get Flickr image data
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
                $preview_size = array_key_first($image_data);
            }

            if (isset($image_data[$preview_size])) {
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
}

// Include required files
require_once FLICKR_JUSTIFIED_PLUGIN_PATH . 'includes/render.php';
require_once FLICKR_JUSTIFIED_PLUGIN_PATH . 'includes/admin-settings.php';

// Initialize the plugin
FlickrJustifiedBlock::init();
