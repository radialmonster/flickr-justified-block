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
            // Check if builtin lightbox is enabled
            $use_builtin_lightbox = false;
            if (class_exists('FlickrJustifiedAdminSettings') && method_exists('FlickrJustifiedAdminSettings', 'get_use_builtin_lightbox')) {
                $use_builtin_lightbox = FlickrJustifiedAdminSettings::get_use_builtin_lightbox();
            }

            if ($use_builtin_lightbox) {
                // Enqueue built-in PhotoSwipe script
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
            } else {
                // Enqueue third-party lightbox enhancement script
                $lightbox_js_path = FLICKR_JUSTIFIED_PLUGIN_PATH . 'assets/js/lightbox-enhancement.js';
                $lightbox_js_ver  = @filemtime($lightbox_js_path);

                wp_enqueue_script(
                    'flickr-justified-lightbox-enhancement',
                    FLICKR_JUSTIFIED_PLUGIN_URL . 'assets/js/lightbox-enhancement.js',
                    [],
                    $lightbox_js_ver ? $lightbox_js_ver : FLICKR_JUSTIFIED_VERSION,
                    true
                );
            }

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

    
}

// Include required files
require_once FLICKR_JUSTIFIED_PLUGIN_PATH . 'includes/render.php';
require_once FLICKR_JUSTIFIED_PLUGIN_PATH . 'includes/admin-settings.php';

// Initialize the plugin
FlickrJustifiedBlock::init();
