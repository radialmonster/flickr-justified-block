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
        if (!function_exists('register_block_type')) {
            return;
        }

        register_block_type('flickr-justified/block', [
            'render_callback' => 'flickr_justified_render_block',
            'attributes' => [
                'urls' => [
                    'type' => 'string',
                    'default' => ''
                ],
                'gap' => [
                    'type' => 'number',
                    'default' => 12
                ],
                'imageSize' => [
                    'type' => 'string',
                    'default' => 'large',
                    'enum' => ['medium', 'large', 'large1600', 'large2048', 'original']
                ],
                'lightboxMaxWidth' => [
                    'type' => 'number',
                    'default' => 2048
                ],
                'lightboxMaxHeight' => [
                    'type' => 'number',
                    'default' => 2048
                ],
                'randomBreakouts' => [
                    'type' => 'boolean',
                    'default' => false
                ],
                'breakoutProbability' => [
                    'type' => 'number',
                    'default' => 15
                ]
            ],
            'supports' => [
                'align' => ['wide', 'full'],
                'anchor' => true,
                'spacing' => [
                    'margin' => true,
                    'padding' => true
                ]
            ]
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
            ['wp-blocks', 'wp-components', 'wp-element', 'wp-editor', 'wp-i18n'],
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

        wp_enqueue_style(
            'flickr-justified-style',
            FLICKR_JUSTIFIED_PLUGIN_URL . 'assets/css/style.css',
            ['wp-block-library'],
            $style_ver ? $style_ver : FLICKR_JUSTIFIED_VERSION
        );

        // Only enqueue JavaScript on frontend
        if (!is_admin()) {
            $lightbox_js_path = FLICKR_JUSTIFIED_PLUGIN_PATH . 'assets/js/lightbox-enhancement.js';
            $lightbox_js_ver  = @filemtime($lightbox_js_path);

            wp_enqueue_script(
                'flickr-justified-lightbox-enhancement',
                FLICKR_JUSTIFIED_PLUGIN_URL . 'assets/js/lightbox-enhancement.js',
                [],
                $lightbox_js_ver ? $lightbox_js_ver : FLICKR_JUSTIFIED_VERSION,
                true
            );

            // Enqueue Packery and our initializer. Packery's .pkgd.min.js includes imagesLoaded.
            wp_enqueue_script(
                'packery',
                'https://unpkg.com/packery@2/dist/packery.pkgd.min.js',
                [],
                '2.1.2',
                true
            );

            // Replace the old css-grid-init.js with our new packery-init.js
            $init_js_path = FLICKR_JUSTIFIED_PLUGIN_PATH . 'assets/js/packery-init.js';
            $init_js_ver  = @filemtime($init_js_path);

            wp_enqueue_script(
                'flickr-justified-layout',
                FLICKR_JUSTIFIED_PLUGIN_URL . 'assets/js/packery-init.js',
                ['packery'], // Make sure Packery loads first
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
