<?php
/**
 * Admin Settings for Flickr Justified Block
 *
 * @package FlickrJustifiedBlock
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Settings Class
 */
class FlickrJustifiedAdminSettings {

    /**
     * Initialize admin settings
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_init', [__CLASS__, 'settings_init']);
        add_filter('plugin_action_links_' . plugin_basename(FLICKR_JUSTIFIED_PLUGIN_PATH . 'flickr-justified-block.php'), [__CLASS__, 'add_settings_link']);
        add_action('wp_ajax_test_flickr_api_key', [__CLASS__, 'test_api_key_ajax']);
        add_action('wp_ajax_flickr_rebuild_urls', [__CLASS__, 'ajax_rebuild_urls']);
        add_action('wp_ajax_flickr_warm_batch', [__CLASS__, 'ajax_warm_batch']);
        add_action('wp_ajax_flickr_process_queue', [__CLASS__, 'ajax_process_queue']);
        add_action('wp_ajax_flickr_clear_photo_cache', [__CLASS__, 'ajax_clear_photo_cache']);
        add_action('wp_ajax_flickr_refresh_photo', [__CLASS__, 'ajax_refresh_photo']);
        add_action('wp_ajax_flickr_queue_photo', [__CLASS__, 'ajax_queue_photo']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
    }

    /**
     * Add admin menu
     */
    public static function add_admin_menu() {
        add_options_page(
            __('Flickr Justified Settings', 'flickr-justified-block'),
            __('Flickr Justified', 'flickr-justified-block'),
            'manage_options',
            'flickr-justified-settings',
            [__CLASS__, 'settings_page']
        );

        // Hidden cache browser page (linked from settings, not from the sidebar)
        add_submenu_page(
            'options-general.php',
            __('Flickr Cache Browser', 'flickr-justified-block'),
            __('Flickr Cache Browser', 'flickr-justified-block'),
            'manage_options',
            'flickr-justified-cache-browser',
            [__CLASS__, 'cache_browser_page']
        );

        remove_submenu_page('options-general.php', 'flickr-justified-cache-browser');
    }

    /**
     * Add settings link to plugin page
     */
    public static function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=flickr-justified-settings') . '">' . __('Settings', 'flickr-justified-block') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Initialize settings
     */
    public static function settings_init() {
        register_setting('flickr_justified_settings', 'flickr_justified_options', [
            'sanitize_callback' => [__CLASS__, 'sanitize_options'],
        ]);

        self::maybe_handle_warmer_actions();

        add_settings_section(
            'flickr_justified_api_section',
            __('Flickr API Configuration', 'flickr-justified-block'),
            [__CLASS__, 'api_section_callback'],
            'flickr_justified_settings'
        );

        add_settings_field(
            'api_key',
            __('Flickr API Key', 'flickr-justified-block'),
            [__CLASS__, 'api_key_callback'],
            'flickr_justified_settings',
            'flickr_justified_api_section'
        );

        add_settings_section(
            'flickr_justified_cache_section',
            __('Cache Settings', 'flickr-justified-block'),
            [__CLASS__, 'cache_section_callback'],
            'flickr_justified_settings'
        );

        add_settings_field(
            'cache_duration',
            __('Cache Duration', 'flickr-justified-block'),
            [__CLASS__, 'cache_duration_callback'],
            'flickr_justified_settings',
            'flickr_justified_cache_section'
        );

        add_settings_field(
            'cache_warmer_panel',
            __('Cache & Warmer', 'flickr-justified-block'),
            [__CLASS__, 'cache_warmer_status_callback'],
            'flickr_justified_settings',
            'flickr_justified_cache_section'
        );

        add_settings_section(
            'flickr_justified_breakpoints_section',
            __('Responsive Breakpoints', 'flickr-justified-block'),
            [__CLASS__, 'breakpoints_section_callback'],
            'flickr_justified_settings'
        );

        add_settings_field(
            'breakpoints',
            __('Screen Size Breakpoints', 'flickr-justified-block'),
            [__CLASS__, 'breakpoints_callback'],
            'flickr_justified_settings',
            'flickr_justified_breakpoints_section'
        );

        add_settings_section(
            'flickr_justified_lightbox_section',
            __('Built-in PhotoSwipe Lightbox', 'flickr-justified-block'),
            [__CLASS__, 'lightbox_section_callback'],
            'flickr_justified_settings'
        );

        add_settings_section(
            'flickr_justified_error_section',
            __('Error Handling', 'flickr-justified-block'),
            [__CLASS__, 'error_section_callback'],
            'flickr_justified_settings'
        );

        add_settings_field(
            'privacy_error_mode',
            __('Private Photo Handling', 'flickr-justified-block'),
            [__CLASS__, 'privacy_error_mode_callback'],
            'flickr_justified_settings',
            'flickr_justified_error_section'
        );

        add_settings_field(
            'custom_error_message',
            __('Custom Error Message', 'flickr-justified-block'),
            [__CLASS__, 'custom_error_message_callback'],
            'flickr_justified_settings',
            'flickr_justified_error_section'
        );

        add_settings_section(
            'flickr_justified_attribution_section',
            __('Flickr Attribution', 'flickr-justified-block'),
            [__CLASS__, 'attribution_section_callback'],
            'flickr_justified_settings'
        );

        add_settings_field(
            'attribution_text',
            __('Attribution Text', 'flickr-justified-block'),
            [__CLASS__, 'attribution_text_callback'],
            'flickr_justified_settings',
            'flickr_justified_attribution_section'
        );

        add_settings_field(
            'use_builtin_lightbox',
            __('Built-in Lightbox', 'flickr-justified-block'),
            [__CLASS__, 'use_builtin_lightbox_callback'],
            'flickr_justified_settings',
            'flickr_justified_lightbox_section'
        );
    }

    /**
     * Encrypt API key for database storage
     */
    private static function encrypt_api_key($api_key) {
        if (empty($api_key)) {
            return '';
        }

        // Use WordPress salt for encryption key
        $key = wp_salt('auth');
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($api_key, 'AES-256-CBC', $key, 0, $iv);

        // Store IV with encrypted data, base64 encoded
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt API key from database
     */
    private static function decrypt_api_key($encrypted_api_key) {
        if (empty($encrypted_api_key)) {
            return '';
        }

        $data = base64_decode($encrypted_api_key);
        if ($data === false) {
            return '';
        }

        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);

        $key = wp_salt('auth');
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }

    /**
     * Sanitize options
     */
    public static function sanitize_options($input) {
        $sanitized = [];

        if (isset($input['api_key'])) {
            $api_key = sanitize_text_field($input['api_key']);
            // Only encrypt if it's not empty and doesn't look like it's already masked
            if (!empty($api_key) && !preg_match('/^\*+[a-zA-Z0-9]{4}$/', $api_key)) {
                $sanitized['api_key'] = self::encrypt_api_key($api_key);
            } elseif (!empty($api_key) && preg_match('/^\*+[a-zA-Z0-9]{4}$/', $api_key)) {
                // If it's masked, keep the existing encrypted value
                $current_options = get_option('flickr_justified_options', []);
                $sanitized['api_key'] = isset($current_options['api_key']) ? $current_options['api_key'] : '';
            }
        }

        if (isset($input['cache_duration'])) {
            $sanitized['cache_duration'] = absint($input['cache_duration']);
            if ($sanitized['cache_duration'] < 1) {
                $sanitized['cache_duration'] = 24; // Default to 24 hours
            }
        }

        $sanitized['cache_warmer_enabled'] = !empty($input['cache_warmer_enabled']);
        $sanitized['cache_warmer_slow_mode'] = !empty($input['cache_warmer_slow_mode']);

        if (isset($input['cache_warmer_batch_size'])) {
            $batch_size = absint($input['cache_warmer_batch_size']);
            if ($batch_size < 1) {
                $batch_size = 1;
            }
            if ($batch_size > 25) {
                $batch_size = 25;
            }
            $sanitized['cache_warmer_batch_size'] = $batch_size;
        } else {
            $sanitized['cache_warmer_batch_size'] = 5;
        }

        // Sanitize breakpoints
        if (isset($input['breakpoints']) && is_array($input['breakpoints'])) {
            $sanitized['breakpoints'] = [];
            foreach ($input['breakpoints'] as $key => $value) {
                if (is_numeric($value)) {
                    $sanitized['breakpoints'][$key] = max(200, min(3000, absint($value))); // Clamp between 200-3000px
                }
            }
        }

        // Sanitize default responsive settings
        if (isset($input['default_responsive_settings']) && is_array($input['default_responsive_settings'])) {
            $sanitized['default_responsive_settings'] = [];
            foreach ($input['default_responsive_settings'] as $key => $value) {
                if (is_numeric($value)) {
                    $sanitized['default_responsive_settings'][$key] = max(1, min(8, absint($value))); // Clamp between 1-8 images per row
                }
            }
        }


        // Sanitize privacy error mode
        if (isset($input['privacy_error_mode'])) {
            $mode = sanitize_text_field($input['privacy_error_mode']);
            $valid_modes = ['show_error', 'show_nothing'];
            $sanitized['privacy_error_mode'] = in_array($mode, $valid_modes, true) ? $mode : 'show_error';
        }

        // Sanitize custom error message
        if (isset($input['custom_error_message'])) {
            $message = wp_kses($input['custom_error_message'], [
                'strong' => [],
                'em' => [],
                'br' => [],
                'p' => [],
                'span' => ['style' => []],
                'div' => ['style' => []],
            ]);
            $sanitized['custom_error_message'] = trim($message);
        }

        // Sanitize attribution text
        if (isset($input['attribution_text'])) {
            $text = sanitize_text_field($input['attribution_text']);
            $sanitized['attribution_text'] = !empty($text) ? $text : 'Flickr';
        }

        // Built-in lightbox is always enabled
        $sanitized['use_builtin_lightbox'] = true;

        return $sanitized;
    }

    /**
     * Internal logger (respects WP_DEBUG)
     */
    private static function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (is_array($message) || is_object($message)) {
                $message = print_r($message, true);
            }
            error_log('Flickr Justified Block: ' . $message);
        }
    }

    /**
     * API section callback
     */
    public static function api_section_callback() {
        echo '<p>' . __('Configure your Flickr API settings to enable automatic fetching of high-resolution images from Flickr photo page URLs.', 'flickr-justified-block') . '</p>';
        echo '<p>' . sprintf(
            __('Don\'t have an API key? <a href="%s" target="_blank" rel="noopener">Get one free from Flickr</a>.', 'flickr-justified-block'),
            'https://www.flickr.com/services/apps/create/'
        ) . '</p>';
    }

    /**
     * Cache section callback
     */
    public static function cache_section_callback() {
        echo '<p>' . __('Control how long Flickr image data is cached to improve performance and reduce API usage.', 'flickr-justified-block') . '</p>';
    }

    /**
     * API key field callback
     */
    public static function api_key_callback() {
        $options = get_option('flickr_justified_options', []);
        $encrypted_api_key = isset($options['api_key']) ? $options['api_key'] : '';

        // Display masked version if key exists
        $display_value = '';
        if (!empty($encrypted_api_key)) {
            $decrypted_key = self::decrypt_api_key($encrypted_api_key);
            if (!empty($decrypted_key) && strlen($decrypted_key) >= 4) {
                $display_value = str_repeat('*', strlen($decrypted_key) - 4) . substr($decrypted_key, -4);
            }
        }

        echo '<div style="display: flex; align-items: center; gap: 10px;">';
        echo '<input type="text" id="flickr-api-key-input" name="flickr_justified_options[api_key]" value="' . esc_attr($display_value) . '" class="regular-text" placeholder="' . esc_attr__('Enter your Flickr API key', 'flickr-justified-block') . '" />';
        echo '<button type="button" id="test-api-key" class="button button-secondary">' . __('Test API Key', 'flickr-justified-block') . '</button>';
        echo '</div>';
        echo '<div id="api-test-result" style="margin-top: 10px;"></div>';
        echo '<p class="description">' . __('Required to fetch high-resolution images from Flickr photo page URLs. The block will still work with direct image URLs without an API key.', 'flickr-justified-block') . '</p>';

        if (!empty($encrypted_api_key)) {
            echo '<p class="description" style="color: #46b450;">[OK] ' . __('API key configured', 'flickr-justified-block') . '</p>';
            echo '<p class="description">' . __('To update your API key, clear the field and enter a new one.', 'flickr-justified-block') . '</p>';
        }
    }

    /**
     * Cache duration field callback
     */
    public static function cache_duration_callback() {
        $options = get_option('flickr_justified_options', []);
        $cache_duration = isset($options['cache_duration']) ? $options['cache_duration'] : 168; // Default 7 days (168 hours)

        echo '<input type="number" name="flickr_justified_options[cache_duration]" value="' . esc_attr($cache_duration) . '" min="1" max="8760" class="small-text" />';
        echo ' ' . __('hours', 'flickr-justified-block');
        echo '<p class="description">' . __('How long to cache Flickr image data (1-8760 hours). Default: 168 hours (7 days).', 'flickr-justified-block') . '</p>';
    }

    // Warm settings moved into unified status panel.

    // API call budget moved into unified status panel.

    /**
     * Breakpoints section callback
     */
    public static function breakpoints_section_callback() {
        echo '<p>' . __('Configure responsive breakpoints for different screen sizes. These determine when the gallery layout changes to accommodate different devices.', 'flickr-justified-block') . '</p>';
        echo '<p>' . __('Users can then choose how many images per row to display at each breakpoint in the block editor.', 'flickr-justified-block') . '</p>';
    }

    /**
     * Breakpoints field callback
     */
    public static function breakpoints_callback() {
        $options = get_option('flickr_justified_options', []);
        $breakpoints = isset($options['breakpoints']) ? $options['breakpoints'] : self::get_default_breakpoints();
        $default_responsive = isset($options['default_responsive_settings']) ? $options['default_responsive_settings'] : self::get_default_responsive_settings();

        echo '<table class="form-table">';
        echo '<tbody>';
        echo '<tr>';
        echo '<th style="width: 200px;">' . __('Device Category', 'flickr-justified-block') . '</th>';
        echo '<th style="width: 120px;">' . __('Min Width (px)', 'flickr-justified-block') . '</th>';
        echo '<th>' . __('Default Images per Row', 'flickr-justified-block') . '</th>';
        echo '</tr>';

        $breakpoint_labels = [
            'mobile' => __('Mobile Portrait', 'flickr-justified-block'),
            'mobile_landscape' => __('Mobile Landscape', 'flickr-justified-block'),
            'tablet_portrait' => __('Tablet Portrait', 'flickr-justified-block'),
            'tablet_landscape' => __('Tablet Landscape', 'flickr-justified-block'),
            'desktop' => __('Desktop/Laptop', 'flickr-justified-block'),
            'large_desktop' => __('Large Desktop', 'flickr-justified-block'),
            'extra_large' => __('Ultra-Wide Screens', 'flickr-justified-block')
        ];

        foreach ($breakpoint_labels as $key => $label) {
            $breakpoint_value = isset($breakpoints[$key]) ? $breakpoints[$key] : '';
            $responsive_value = isset($default_responsive[$key]) ? $default_responsive[$key] : 1;
            echo '<tr>';
            echo '<td><strong>' . esc_html($label) . '</strong></td>';
            echo '<td>';
            echo '<input type="number" name="flickr_justified_options[breakpoints][' . esc_attr($key) . ']" value="' . esc_attr($breakpoint_value) . '" min="200" max="3000" class="small-text" placeholder="px" /> px';
            echo '</td>';
            echo '<td>';
            echo '<input type="number" name="flickr_justified_options[default_responsive_settings][' . esc_attr($key) . ']" value="' . esc_attr($responsive_value) . '" min="1" max="8" class="small-text" /> ' . __('images per row', 'flickr-justified-block');
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        echo '<p class="description">' . __('Set the minimum width in pixels for each device category and the default number of images per row. Leave breakpoint empty to disable. Users can override the images per row setting in individual blocks.', 'flickr-justified-block') . '</p>';
        echo '<p class="description"><strong>' . __('Common sizes:', 'flickr-justified-block') . '</strong> Mobile: 320-480px, Tablet: 768-1024px, Desktop: 1280-1440px, Ultra-wide: 1920px+</p>';

        echo '<p><button type="button" id="reset-breakpoints" class="button button-secondary">' . __('Reset to Defaults', 'flickr-justified-block') . '</button></p>';

        // Add JavaScript for reset functionality
        ?>
        <script>
        document.getElementById('reset-breakpoints').addEventListener('click', function() {
            var defaults = <?php echo json_encode(self::get_default_breakpoints()); ?>;
            var defaultResponsive = <?php echo json_encode(self::get_default_responsive_settings()); ?>;
            for (var key in defaults) {
                var breakpointInput = document.querySelector('input[name="flickr_justified_options[breakpoints][' + key + ']"]');
                var responsiveInput = document.querySelector('input[name="flickr_justified_options[default_responsive_settings][' + key + ']"]');
                if (breakpointInput) {
                    breakpointInput.value = defaults[key];
                }
                if (responsiveInput && defaultResponsive[key] !== undefined) {
                    responsiveInput.value = defaultResponsive[key];
                }
            }
        });
        </script>
        <?php
    }

    /**
     * Lightbox section callback
     */
    public static function lightbox_section_callback() {
        echo '<p>' . __('The plugin uses a built-in PhotoSwipe lightbox optimized for Flickr galleries with automatic attribution.', 'flickr-justified-block') . '</p>';
    }


    /**
     * Error section callback
     */
    public static function error_section_callback() {
        echo '<p>' . __('Configure how the plugin handles private or unavailable Flickr photos.', 'flickr-justified-block') . '</p>';
    }

    /**
     * Privacy error mode callback
     */
    public static function privacy_error_mode_callback() {
        $options = get_option('flickr_justified_options', []);
        $mode = isset($options['privacy_error_mode']) ? $options['privacy_error_mode'] : 'show_error';

        echo '<select name="flickr_justified_options[privacy_error_mode]" id="privacy_error_mode">';
        echo '<option value="show_error"' . selected($mode, 'show_error', false) . '>' . __('Show error message', 'flickr-justified-block') . '</option>';
        echo '<option value="show_nothing"' . selected($mode, 'show_nothing', false) . '>' . __('Show nothing (hide the gallery)', 'flickr-justified-block') . '</option>';
        echo '</select>';

        echo '<p class="description">' . __('Choose what happens when a Flickr photo is private or unavailable:', 'flickr-justified-block') . '</p>';
        echo '<ul style="list-style: disc; margin-left: 20px;">';
        echo '<li><strong>' . __('Show error message:', 'flickr-justified-block') . '</strong> ' . __('Display an error box with customizable message', 'flickr-justified-block') . '</li>';
        echo '<li><strong>' . __('Show nothing:', 'flickr-justified-block') . '</strong> ' . __('Hide the gallery completely with just a line break to prevent blocks from running together', 'flickr-justified-block') . '</li>';
        echo '</ul>';
    }

    /**
     * Custom error message callback
     */
    public static function custom_error_message_callback() {
        $options = get_option('flickr_justified_options', []);
        $message = isset($options['custom_error_message']) ? $options['custom_error_message'] : '';

        if (empty($message)) {
            $message = "Gallery not available\n\nPlease check your Flickr API key in the plugin settings.";
        }

        echo '<textarea name="flickr_justified_options[custom_error_message]" id="custom_error_message" rows="4" cols="50" class="large-text">' . esc_textarea($message) . '</textarea>';
        echo '<p class="description">' . __('Custom message to display when photos are private or unavailable. You can use basic HTML tags like &lt;strong&gt;, &lt;em&gt;, &lt;br&gt;, etc.', 'flickr-justified-block') . '</p>';
        echo '<p class="description">' . __('This setting only applies when "Show error message" is selected above.', 'flickr-justified-block') . '</p>';

        // Add JavaScript to show/hide this field based on the mode selection
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var modeSelect = document.getElementById('privacy_error_mode');
            var messageRow = document.getElementById('custom_error_message').closest('tr');

            function toggleMessageField() {
                if (modeSelect.value === 'show_error') {
                    messageRow.style.display = '';
                } else {
                    messageRow.style.display = 'none';
                }
            }

            modeSelect.addEventListener('change', toggleMessageField);
            toggleMessageField(); // Initial call
        });
        </script>
        <?php
    }

    /**
     * Attribution section callback
     */
    public static function attribution_section_callback() {
        echo '<p>' . __('Configure how Flickr attribution links are displayed to comply with Flickr\'s terms of service.', 'flickr-justified-block') . '</p>';
        echo '<p><strong>' . __('Note:', 'flickr-justified-block') . '</strong> ' . __('Flickr\'s terms require attribution links back to the original photo pages when hosting images.', 'flickr-justified-block') . '</p>';
        echo '<p>' . __('The built-in PhotoSwipe lightbox always includes a “View on Flickr” button, so attribution is guaranteed for every image.', 'flickr-justified-block') . '</p>';
    }

    /**
     * Attribution text callback
     */
    public static function attribution_text_callback() {
        $options = get_option('flickr_justified_options', []);
        $text = isset($options['attribution_text']) ? $options['attribution_text'] : 'Flickr';

        echo '<input type="text" name="flickr_justified_options[attribution_text]" id="attribution_text" value="' . esc_attr($text) . '" class="regular-text" />';
        echo '<p class="description">' . __('Text to display for the Flickr attribution button. Default: "Flickr"', 'flickr-justified-block') . '</p>';
        echo '<p class="description">' . __('Examples: "Flickr", "View on Flickr", "Source", "Original", "📷 Flickr"', 'flickr-justified-block') . '</p>';
    }

    /**
     * Render cache & warmer unified panel.
     */
    public static function cache_warmer_status_callback() {
        $options = get_option('flickr_justified_options', []);
        $enabled = array_key_exists('cache_warmer_enabled', $options) ? (bool) $options['cache_warmer_enabled'] : true;
        $slow_mode = array_key_exists('cache_warmer_slow_mode', $options) ? (bool) $options['cache_warmer_slow_mode'] : true;
        $photostream_enabled = array_key_exists('cache_warmer_photostream_enabled', $options) ? (bool) $options['cache_warmer_photostream_enabled'] : true;
        $max_seconds = isset($options['cache_warmer_max_seconds']) ? absint($options['cache_warmer_max_seconds']) : 20;
        $max_calls = self::get_cache_warmer_api_calls_per_run();

        $last_run = get_option('flickr_justified_cache_warmer_last_run', []);
        $pause_until = (int) get_option('flickr_justified_cache_warmer_pause_until', 0);
        $next_run = wp_next_scheduled('flickr_justified_run_cache_warmer');

        global $wpdb;
        $jobs_table = $wpdb->prefix . 'fjb_jobs';
        $pending_jobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'pending'");
        $due_jobs = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$jobs_table} WHERE status = 'pending' AND (not_before IS NULL OR not_before <= NOW())");
        $backoff_jobs = $pending_jobs - $due_jobs;

        $fmt_time = function($ts) {
            if (!$ts) { return '—'; }
            return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $ts);
        };

        echo '<div style="padding:12px; border:1px solid #ccd0d4; border-radius:4px; background:#f6f7f7;">';
        echo '<p><strong>' . __('Cache & Warmer', 'flickr-justified-block') . '</strong></p>';

        echo '<div style="margin-bottom:8px;">';
        echo '<label><input type="checkbox" name="flickr_justified_options[cache_warmer_enabled]" value="1" ' . checked($enabled, true, false) . ' /> ' . esc_html__('Enable background warming (WP-Cron)', 'flickr-justified-block') . '</label><br />';
        echo '<label><input type="checkbox" name="flickr_justified_options[cache_warmer_slow_mode]" value="1" ' . checked($slow_mode, true, false) . ' /> ' . esc_html__('Slow mode (space out runs)', 'flickr-justified-block') . '</label><br />';
        echo '<label><input type="checkbox" name="flickr_justified_options[cache_warmer_photostream_enabled]" value="1" ' . checked($photostream_enabled, true, false) . ' /> ' . esc_html__('Warm full photostream (500 per call)', 'flickr-justified-block') . '</label><br />';
        echo '<label>' . esc_html__('API calls per run', 'flickr-justified-block') . ': <input type="number" name="flickr_justified_options[cache_warmer_batch_size]" value="' . esc_attr($max_calls) . '" min="1" max="200" class="small-text" /> </label><br />';
        echo '<label>' . esc_html__('Seconds per run budget', 'flickr-justified-block') . ': <input type="number" name="flickr_justified_options[cache_warmer_max_seconds]" value="' . esc_attr($max_seconds) . '" min="5" max="60" class="small-text" /> </label>';
        echo '</div>';

        echo '<ul style="margin:0 0 10px 18px; list-style:disc;">';
        echo '<li>' . __('Queue length (pending)', 'flickr-justified-block') . ': ' . $pending_jobs . '</li>';
        echo '<li>' . __('Ready to run', 'flickr-justified-block') . ': ' . $due_jobs . '</li>';
        echo '<li>' . __('Backed off', 'flickr-justified-block') . ': ' . max(0, $backoff_jobs) . '</li>';
        $debug_jobs_url = admin_url('admin-ajax.php?action=flickr_justified_debug_jobs');
        echo '<li>' . __('Debug jobs', 'flickr-justified-block') . ': <a href="' . esc_url($debug_jobs_url) . '" target="_blank" rel="noopener">' . esc_html($debug_jobs_url) . '</a></li>';
        echo '<li>' . __('Next run', 'flickr-justified-block') . ': ' . ($next_run ? $fmt_time($next_run) : '—') . '</li>';
        echo '<li>' . __('Pause until', 'flickr-justified-block') . ': ' . ($pause_until ? $fmt_time($pause_until) : '—') . '</li>';
        if (!empty($last_run) && is_array($last_run)) {
            $ts = isset($last_run['ts']) ? (int) $last_run['ts'] : 0;
            $processed = isset($last_run['processed']) ? (int) $last_run['processed'] : 0;
            $rl = !empty($last_run['rate_limited']);
            $err = isset($last_run['last_error']) ? sanitize_text_field((string) $last_run['last_error']) : '';
            echo '<li>' . __('Last run', 'flickr-justified-block') . ': ' . ($ts ? $fmt_time($ts) : '—') . ' ';
            echo '(' . sprintf(__('processed %d, rate_limited: %s', 'flickr-justified-block'), $processed, $rl ? 'yes' : 'no') . ')';
            if ($err) {
                echo '<br><small>' . esc_html($err) . '</small>';
            }
            echo '</li>';
        }
        echo '</ul>';

        echo '<p>';
        submit_button(__('Run warmer now', 'flickr-justified-block'), 'secondary', 'fjb_run_warmer_now', false);
        submit_button(__('Rebuild queue from content', 'flickr-justified-block'), 'secondary', 'fjb_rebuild_queue', false);
        submit_button(__('Reset queue to bulk mode', 'flickr-justified-block'), 'secondary', 'fjb_reset_bulk_queue', false);
        submit_button(__('Requeue missing metadata', 'flickr-justified-block'), 'secondary', 'fjb_requeue_missing_meta', false);
        echo '</p>';

        echo '<p class="description">' . __('Note: 1 API call warms up to 500 photos. Raise calls/run to go faster; lower to be gentler. Rate limit: ~3600 calls/hour.', 'flickr-justified-block') . '</p>';
        echo '</div>';
    }

    /**
     * Process warmer actions from the settings form.
     */
    private static function maybe_handle_warmer_actions() {
        if (!is_admin() || !isset($_POST['option_page']) || 'flickr_justified_settings' !== $_POST['option_page']) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('flickr_justified_settings-options');

        if (isset($_POST['fjb_run_warmer_now'])) {
            // Schedule an immediate warmer run to avoid request timeouts in admin
            if (!wp_next_scheduled('flickr_justified_run_cache_warmer')) {
                wp_schedule_single_event(time() + 5, 'flickr_justified_run_cache_warmer');
            }
            add_settings_error('flickr_justified_settings', 'fjb_warmer_run', __('Cache warmer queued to run now.', 'flickr-justified-block'), 'updated');
        }

        if (isset($_POST['fjb_clear_warmer_failures'])) {
            delete_option('flickr_justified_cache_warmer_failures');
            delete_option('flickr_justified_cache_warmer_pause_until');
            global $wpdb;
            $jobs_table = $wpdb->prefix . 'fjb_jobs';
            $wpdb->query("UPDATE {$jobs_table} SET not_before = NULL, attempts = 0, last_error = NULL WHERE status = 'pending'");
            add_settings_error('flickr_justified_settings', 'fjb_warmer_cleared', __('Cache warmer failures/backoff cleared.', 'flickr-justified-block'), 'updated');
        }

        if (isset($_POST['fjb_rebuild_queue'])) {
            if (class_exists('FlickrJustifiedCacheWarmer')) {
                try {
                    FlickrJustifiedCacheWarmer::rebuild_queue_from_content(true);
                    add_settings_error('flickr_justified_settings', 'fjb_queue_rebuilt', __('Queue rebuilt from content.', 'flickr-justified-block'), 'updated');
                } catch (Throwable $e) {
                    add_settings_error(
                        'flickr_justified_settings',
                        'fjb_queue_rebuilt_fail',
                        sprintf(__('Queue rebuild failed: %s', 'flickr-justified-block'), esc_html($e->getMessage())),
                        'error'
                    );
                }
            }
        }

        if (isset($_POST['fjb_reset_bulk_queue'])) {
            if (class_exists('FlickrJustifiedCacheWarmer')) {
                try {
                    FlickrJustifiedCacheWarmer::reset_to_bulk_queue();
                    add_settings_error('flickr_justified_settings', 'fjb_queue_reset_bulk', __('Queue reset to bulk mode (photo jobs dropped, albums/photostream re-queued).', 'flickr-justified-block'), 'updated');
                } catch (Throwable $e) {
                    add_settings_error('flickr_justified_settings', 'fjb_queue_reset_bulk_fail', sprintf(__('Queue reset failed: %s', 'flickr-justified-block'), esc_html($e->getMessage())), 'error');
                }
            }
        }

        if (isset($_POST['fjb_requeue_missing_meta'])) {
            if (class_exists('FlickrJustifiedCacheWarmer')) {
                try {
                    $count = FlickrJustifiedCacheWarmer::requeue_missing_metadata();
                    add_settings_error(
                        'flickr_justified_settings',
                        'fjb_requeue_missing_meta',
                        sprintf(__('Requeued %d album/photostream jobs to backfill missing photo metadata.', 'flickr-justified-block'), (int) $count),
                        'updated'
                    );
                } catch (Throwable $e) {
                    add_settings_error(
                        'flickr_justified_settings',
                        'fjb_requeue_missing_meta_fail',
                        sprintf(__('Requeue failed: %s', 'flickr-justified-block'), esc_html($e->getMessage())),
                        'error'
                    );
                }
            }
        }
    }

    /**
     * Use builtin lightbox callback
     */
    public static function use_builtin_lightbox_callback() {
        echo '<div style="background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 4px; padding: 15px; margin: 10px 0;">';
        echo '<p><strong>' . __('✓ Built-in PhotoSwipe lightbox is always enabled', 'flickr-justified-block') . '</strong></p>';
        echo '<p>' . __('This plugin now exclusively uses a built-in PhotoSwipe lightbox optimized for Flickr galleries.', 'flickr-justified-block') . '</p>';
        echo '</div>';

        echo '<p class="description"><strong>' . __('Features:', 'flickr-justified-block') . '</strong></p>';
        echo '<ul style="list-style: disc; margin-left: 20px;">';
        echo '<li>' . __('Guaranteed Flickr attribution button in toolbar', 'flickr-justified-block') . '</li>';
        echo '<li>' . __('Consistent lightbox behavior across themes', 'flickr-justified-block') . '</li>';
        echo '<li>' . __('No dependency on third-party lightbox plugins', 'flickr-justified-block') . '</li>';
        echo '<li>' . __('Optimized for high-resolution displays', 'flickr-justified-block') . '</li>';
        echo '</ul>';
    }


    /**
     * Get privacy error mode from settings
     */
    public static function get_privacy_error_mode() {
        $options = get_option('flickr_justified_options', []);
        $mode = isset($options['privacy_error_mode']) ? $options['privacy_error_mode'] : 'show_error';
        return in_array($mode, ['show_error', 'show_nothing'], true) ? $mode : 'show_error';
    }

    /**
     * Get custom error message from settings
     */
    public static function get_custom_error_message() {
        $options = get_option('flickr_justified_options', []);
        $message = isset($options['custom_error_message']) ? trim($options['custom_error_message']) : '';

        if (empty($message)) {
            return "Gallery not available\n\nPlease check your Flickr API key in the plugin settings.";
        }

        return $message;
    }

    /**
     * Get attribution text from settings
     */
    public static function get_attribution_text() {
        $options = get_option('flickr_justified_options', []);
        $text = isset($options['attribution_text']) ? trim($options['attribution_text']) : '';
        return !empty($text) ? $text : 'Flickr';
    }

    /**
     * Get use builtin lightbox from settings
     */
    public static function get_use_builtin_lightbox() {
        // Always return true since we always use built-in PhotoSwipe
        return true;
    }

    /**
     * Settings page
     */
    public static function settings_page() {
        if (isset($_GET['settings-updated'])) {
            add_settings_error('flickr_justified_messages', 'flickr_justified_message', __('Settings saved successfully!', 'flickr-justified-block'), 'updated');
        }

        settings_errors('flickr_justified_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="notice notice-info">
                <p>
                    <strong><?php _e('How to use:', 'flickr-justified-block'); ?></strong>
                    <?php _e('Add the "Flickr Justified" block to any post or page, then paste Flickr photo links, album URLs, or direct image links (one per line) in the block settings.', 'flickr-justified-block'); ?>
                </p>
            </div>

            <form action="options.php" method="post">
                <?php
                settings_fields('flickr_justified_settings');
                do_settings_sections('flickr_justified_settings');
                submit_button(__('Save Settings', 'flickr-justified-block'));
                ?>
            </form>

            <p style="margin-top: 12px;">
                <a class="button" href="<?php echo esc_url(admin_url('options-general.php?page=flickr-justified-cache-browser')); ?>" target="_blank" rel="noopener">
                    <?php _e('Open Cache Browser', 'flickr-justified-block'); ?>
                </a>
            </p>

            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('Support This Plugin', 'flickr-justified-block'); ?></h2>
                <p><?php _e('Enjoying this plugin? A small donation helps me keep improving it. Totally optional, but your support means a lot!', 'flickr-justified-block'); ?></p>
                <p>
                    <a href="https://radialmonster.github.io/send-a-virtual-gift/" target="_blank" rel="noopener noreferrer" class="button button-primary">
                        <?php _e('Send a Virtual Gift', 'flickr-justified-block'); ?>
                    </a>
                </p>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('Supported Sources', 'flickr-justified-block'); ?></h2>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><strong><?php _e('Flickr Photo Pages:', 'flickr-justified-block'); ?></strong> https://www.flickr.com/photos/username/1234567890/</li>
                    <li><strong><?php _e('Flickr Albums/Sets:', 'flickr-justified-block'); ?></strong> https://www.flickr.com/photos/username/albums/72177720301234567</li>
                    <li><strong><?php _e('Direct Images:', 'flickr-justified-block'); ?></strong> https://example.com/image.jpg</li>
                    <li><strong><?php _e('Supported File Types:', 'flickr-justified-block'); ?></strong> JPG, PNG, WebP, AVIF, GIF, SVG</li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Cache browser page.
     */
    public static function cache_browser_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $meta_table = $wpdb->prefix . 'fjb_photo_meta';
        $jobs_table = $wpdb->prefix . 'fjb_jobs';

        $options = get_option('flickr_justified_options', []);
        $cache_hours = isset($options['cache_duration']) ? max(1, (int) $options['cache_duration']) : 168;
        $cache_ttl_seconds = $cache_hours * HOUR_IN_SECONDS;
        $format_expiry = function($views_checked_at) use ($cache_ttl_seconds) {
            if (empty($views_checked_at) || $views_checked_at === '0000-00-00 00:00:00') {
                return __('expired', 'flickr-justified-block');
            }
            $start = strtotime($views_checked_at);
            if (!$start) {
                return __('expired', 'flickr-justified-block');
            }
            $expires = $start + $cache_ttl_seconds;
            $delta = $expires - current_time('timestamp');
            $sign = $delta < 0 ? '-' : '';
            $abs = abs($delta);
            $days = floor($abs / DAY_IN_SECONDS);
            $hours = floor(($abs % DAY_IN_SECONDS) / HOUR_IN_SECONDS);
            $mins = floor(($abs % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);
            if ($days > 0) {
                return sprintf('%s%dd %dh', $sign, $days, $hours);
            }
            if ($hours > 0) {
                return sprintf('%s%dh %dm', $sign, $hours, $mins);
            }
            return sprintf('%s%dm', $sign, $mins);
        };

        $photo_search = isset($_GET['fjb_photo_search']) ? sanitize_text_field($_GET['fjb_photo_search']) : '';
        $set_search = isset($_GET['fjb_set_search']) ? sanitize_text_field($_GET['fjb_set_search']) : '';
        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        $membership_table = $wpdb->prefix . 'fjb_membership';

        // Build query with optional filters
        $where = '';
        $join = '';
        $params = [];
        $count_params = [];

        if ($photo_search !== '' && $set_search !== '') {
            // Both filters active
            $join = "INNER JOIN {$membership_table} m ON {$meta_table}.photo_id = m.photo_id";
            $where = 'WHERE ' . $meta_table . '.photo_id LIKE %s AND m.photoset_id LIKE %s';
            $params = ['%' . $wpdb->esc_like($photo_search) . '%', '%' . $wpdb->esc_like($set_search) . '%'];
            $count_params = $params;
        } elseif ($photo_search !== '') {
            // Only photo filter
            $where = 'WHERE photo_id LIKE %s';
            $params = ['%' . $wpdb->esc_like($photo_search) . '%'];
            $count_params = $params;
        } elseif ($set_search !== '') {
            // Only set filter
            $join = "INNER JOIN {$membership_table} m ON {$meta_table}.photo_id = m.photo_id";
            $where = 'WHERE m.photoset_id LIKE %s';
            $params = ['%' . $wpdb->esc_like($set_search) . '%'];
            $count_params = $params;
        }

        // Count total with filters
        $count_query = "SELECT COUNT(DISTINCT {$meta_table}.photo_id) FROM {$meta_table} {$join} {$where}";
        $total = (int) $wpdb->get_var($count_params ? $wpdb->prepare($count_query, ...$count_params) : $count_query);

        // Get rows with filters
        $select_query = "SELECT DISTINCT {$meta_table}.photo_id, {$meta_table}.server, {$meta_table}.secret, {$meta_table}.views, {$meta_table}.views_checked_at, {$meta_table}.updated_at FROM {$meta_table} {$join} {$where} ORDER BY {$meta_table}.updated_at DESC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, [$per_page, $offset]);
        $rows = $wpdb->get_results($wpdb->prepare($select_query, ...$query_params));

        // Build pending jobs query with optional filters
        $jobs_where = "WHERE status = 'pending'";
        $jobs_params = [];

        if ($photo_search !== '' && $set_search !== '') {
            // Filter by both photo ID and set ID
            $jobs_where .= " AND (job_key LIKE %s OR payload_json LIKE %s OR job_key LIKE %s OR payload_json LIKE %s)";
            $jobs_params = [
                '%' . $wpdb->esc_like($photo_search) . '%',
                '%' . $wpdb->esc_like($photo_search) . '%',
                '%' . $wpdb->esc_like($set_search) . '%',
                '%' . $wpdb->esc_like($set_search) . '%'
            ];
        } elseif ($photo_search !== '') {
            // Filter by photo ID only
            $jobs_where .= " AND (job_key LIKE %s OR payload_json LIKE %s)";
            $jobs_params = [
                '%' . $wpdb->esc_like($photo_search) . '%',
                '%' . $wpdb->esc_like($photo_search) . '%'
            ];
        } elseif ($set_search !== '') {
            // Filter by set ID only
            $jobs_where .= " AND (job_key LIKE %s OR payload_json LIKE %s)";
            $jobs_params = [
                '%' . $wpdb->esc_like($set_search) . '%',
                '%' . $wpdb->esc_like($set_search) . '%'
            ];
        }

        $jobs_query = "SELECT job_key, job_type, priority, not_before, attempts, last_error, status, created_at FROM {$jobs_table} {$jobs_where} ORDER BY priority DESC, created_at ASC LIMIT 50";
        $jobs = $jobs_params ? $wpdb->get_results($wpdb->prepare($jobs_query, ...$jobs_params)) : $wpdb->get_results($jobs_query);

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Flickr Cache Browser', 'flickr-justified-block'); ?></h1>

            <h2><?php echo esc_html__('Photo Meta', 'flickr-justified-block'); ?></h2>

            <div style="margin-bottom: 20px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                <strong><?php esc_html_e('Queue Photo for Processing:', 'flickr-justified-block'); ?></strong>
                <div style="margin-top: 8px;">
                    <input type="text" id="fjb-queue-photo-id" placeholder="<?php esc_attr_e('Enter Photo ID (e.g., 128197508)', 'flickr-justified-block'); ?>" style="width: 250px;" />
                    <button type="button" id="fjb-queue-photo-btn" class="button button-primary"><?php esc_html_e('Queue Photo', 'flickr-justified-block'); ?></button>
                    <span id="fjb-queue-result" style="margin-left: 10px;"></span>
                </div>
                <p class="description" style="margin-top: 8px;"><?php esc_html_e('Enter a photo ID to manually fetch and cache it. Useful for adding new photos or re-processing existing ones.', 'flickr-justified-block'); ?></p>
            </div>

            <form method="get" style="margin-bottom: 12px;">
                <input type="hidden" name="page" value="flickr-justified-cache-browser" />
                <label><?php esc_html_e('Photo ID', 'flickr-justified-block'); ?> <input type="text" name="fjb_photo_search" value="<?php echo esc_attr($photo_search); ?>" placeholder="<?php esc_attr_e('e.g., 12345', 'flickr-justified-block'); ?>" /></label>
                <label style="margin-left: 10px;"><?php esc_html_e('Set ID', 'flickr-justified-block'); ?> <input type="text" name="fjb_set_search" value="<?php echo esc_attr($set_search); ?>" placeholder="<?php esc_attr_e('e.g., 72177720301234567', 'flickr-justified-block'); ?>" /></label>
                <button class="button"><?php esc_html_e('Search', 'flickr-justified-block'); ?></button>
                <?php if ($photo_search !== '' || $set_search !== '') : ?>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=flickr-justified-cache-browser')); ?>" class="button"><?php esc_html_e('Clear', 'flickr-justified-block'); ?></a>
                <?php endif; ?>
            </form>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Photo ID', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Server', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Secret', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Views', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Views Checked At', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Cache Expires', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Updated At', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Debug', 'flickr-justified-block'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($rows)) : ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row->photo_id); ?></td>
                            <td><?php echo esc_html($row->server); ?></td>
                            <td><?php echo esc_html($row->secret); ?></td>
                            <td><?php echo esc_html($row->views); ?></td>
                            <td><?php echo esc_html($row->views_checked_at); ?></td>
                            <td><?php echo esc_html($format_expiry($row->views_checked_at)); ?></td>
                            <td><?php echo esc_html($row->updated_at); ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=flickr_justified_debug_photo&photo_id=' . rawurlencode($row->photo_id))); ?>" target="_blank" rel="noopener"><?php esc_html_e('Debug', 'flickr-justified-block'); ?></a>
                                |
                                <a href="#" class="fjb-refresh-photo" data-photo-id="<?php echo esc_attr($row->photo_id); ?>" style="color: #2271b1;"><?php esc_html_e('Refresh', 'flickr-justified-block'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="8">
                        <?php
                        if ($photo_search !== '' || $set_search !== '') {
                            esc_html_e('No records matching search.', 'flickr-justified-block');
                        } else {
                            esc_html_e('No records found.', 'flickr-justified-block');
                        }
                        ?>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php
            $total_pages = max(1, ceil($total / $per_page));
            if ($total_pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';

                // Build base URL for pagination
                $base_url = remove_query_arg('paged', add_query_arg([
                    'page' => 'flickr-justified-cache-browser',
                    'fjb_photo_search' => $photo_search,
                    'fjb_set_search' => $set_search
                ], admin_url('options-general.php')));

                $pagination_args = [
                    'base' => $base_url . '%_%',
                    'format' => '&paged=%#%',
                    'current' => $page,
                    'total' => $total_pages,
                    'prev_text' => '&laquo; ' . __('Previous', 'flickr-justified-block'),
                    'next_text' => __('Next', 'flickr-justified-block') . ' &raquo;',
                    'mid_size' => 2,
                    'end_size' => 1,
                    'type' => 'plain'
                ];

                $pagination = paginate_links($pagination_args);
                if ($pagination) {
                    echo '<span class="displaying-num">' . sprintf(
                        _n('%s item', '%s items', $total, 'flickr-justified-block'),
                        number_format_i18n($total)
                    ) . '</span>';
                    echo $pagination;
                }

                echo '</div></div>';
            }
            ?>

            <h2 style="margin-top:20px;"><?php echo esc_html__('Pending Jobs', 'flickr-justified-block'); ?></h2>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Job Key', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Type', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Priority', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Not Before', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Attempts', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Last Error', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Status', 'flickr-justified-block'); ?></th>
                        <th><?php esc_html_e('Created', 'flickr-justified-block'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($jobs)) : ?>
                    <?php foreach ($jobs as $job) : ?>
                        <tr>
                            <td><?php echo esc_html($job->job_key); ?></td>
                            <td><?php echo esc_html($job->job_type); ?></td>
                            <td><?php echo esc_html($job->priority); ?></td>
                            <td><?php echo esc_html($job->not_before); ?></td>
                            <td><?php echo esc_html($job->attempts); ?></td>
                            <td><?php echo esc_html($job->last_error); ?></td>
                            <td><?php echo esc_html($job->status); ?></td>
                            <td><?php echo esc_html($job->created_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr><td colspan="8">
                        <?php
                        if ($photo_search !== '' || $set_search !== '') {
                            esc_html_e('No pending jobs matching search.', 'flickr-justified-block');
                        } else {
                            esc_html_e('No pending jobs.', 'flickr-justified-block');
                        }
                        ?>
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Queue photo button handler
            $('#fjb-queue-photo-btn').on('click', function(e) {
                e.preventDefault();

                var $btn = $(this);
                var $input = $('#fjb-queue-photo-id');
                var $result = $('#fjb-queue-result');
                var photoId = $input.val().trim();

                if (!photoId) {
                    $result.html('<span style="color: #d63638;">Please enter a photo ID</span>');
                    return;
                }

                if (!/^\d+$/.test(photoId)) {
                    $result.html('<span style="color: #d63638;">Invalid photo ID - must be numbers only</span>');
                    return;
                }

                $btn.prop('disabled', true).text('<?php esc_html_e('Queueing...', 'flickr-justified-block'); ?>');
                $result.html('');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'flickr_queue_photo',
                        photo_id: photoId,
                        nonce: '<?php echo wp_create_nonce('fjb_queue_photo'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<span style="color: #00a32a;">✓ ' + response.data.message + '</span>');
                            $input.val('');
                        } else {
                            $result.html('<span style="color: #d63638;">✗ ' + response.data.message + '</span>');
                        }
                    },
                    error: function() {
                        $result.html('<span style="color: #d63638;">✗ <?php esc_html_e('An error occurred. Please try again.', 'flickr-justified-block'); ?></span>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('<?php esc_html_e('Queue Photo', 'flickr-justified-block'); ?>');
                    }
                });
            });

            // Allow Enter key to submit
            $('#fjb-queue-photo-id').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    $('#fjb-queue-photo-btn').click();
                }
            });

            // Refresh photo button handler
            $('.fjb-refresh-photo').on('click', function(e) {
                e.preventDefault();

                var $link = $(this);
                var photoId = $link.data('photo-id');

                if (!confirm('<?php esc_html_e('Clear cache and requeue this photo for processing?', 'flickr-justified-block'); ?>')) {
                    return;
                }

                $link.text('<?php esc_html_e('Processing...', 'flickr-justified-block'); ?>').css('opacity', '0.5');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'flickr_refresh_photo',
                        photo_id: photoId,
                        nonce: '<?php echo wp_create_nonce('fjb_refresh_photo'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert('<?php esc_html_e('Error:', 'flickr-justified-block'); ?> ' + response.data.message);
                            $link.text('<?php esc_html_e('Refresh', 'flickr-justified-block'); ?>').css('opacity', '1');
                        }
                    },
                    error: function() {
                        alert('<?php esc_html_e('An error occurred. Please try again.', 'flickr-justified-block'); ?>');
                        $link.text('<?php esc_html_e('Refresh', 'flickr-justified-block'); ?>').css('opacity', '1');
                    }
                });
            });
        });
        </script>
        <?php
    }


    /**
     * Handle cache clearing
     */
    public static function handle_cache_clear() {
        if (isset($_POST['action']) && $_POST['action'] === 'clear_flickr_cache') {
            if (!wp_verify_nonce($_POST['flickr_justified_clear_cache_nonce'], 'flickr_justified_clear_cache')) {
                wp_die(__('Security check failed', 'flickr-justified-block'));
            }

            if (!current_user_can('manage_options')) {
                wp_die(__('Insufficient permissions', 'flickr-justified-block'));
            }

            // Use the new centralized cache clearing
            if (class_exists('FlickrJustifiedCache')) {
                FlickrJustifiedCache::clear_all();
                self::log('Cleared all Flickr Justified cache: transients, cache warmer queue, known URLs, and rate limits');
            }

            wp_redirect(add_query_arg(['page' => 'flickr-justified-settings', 'cache-cleared' => '1'], admin_url('options-general.php')));
            exit;
        }

        if (isset($_GET['cache-cleared'])) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' .
                     __('Flickr cache cleared successfully! All cached photo data, album data, cache warmer queue, and rate limits have been reset.', 'flickr-justified-block') .
                     '</p></div>';
            });
        }
    }

    /**
     * Get API key from settings
     */
    public static function get_api_key() {
        $options = get_option('flickr_justified_options', []);
        $encrypted_api_key = isset($options['api_key']) ? $options['api_key'] : '';

        if (empty($encrypted_api_key)) {
            return '';
        }

        // Decrypt the API key
        $decrypted_key = self::decrypt_api_key($encrypted_api_key);

        if (empty($decrypted_key)) {
            return '';
        }

        return trim($decrypted_key);
    }

    /**
     * Get cache duration from settings
     */
    public static function get_cache_duration() {
        $options = get_option('flickr_justified_options', []);
        $duration = isset($options['cache_duration']) ? absint($options['cache_duration']) : 168;
        return $duration * HOUR_IN_SECONDS; // Convert hours to seconds
    }

    /**
     * Determine whether the cache warmer is enabled.
     */
    public static function is_cache_warmer_enabled() {
        $options = get_option('flickr_justified_options', []);
        if (!array_key_exists('cache_warmer_enabled', $options)) {
            return true;
        }

        return (bool) $options['cache_warmer_enabled'];
    }

    /**
     * Determine whether slow mode should be used for the cache warmer.
     */
    public static function is_cache_warmer_slow_mode() {
        $options = get_option('flickr_justified_options', []);
        if (!array_key_exists('cache_warmer_slow_mode', $options)) {
            return true;
        }

        return (bool) $options['cache_warmer_slow_mode'];
    }

    /**
     * Retrieve the configured cache warmer batch size.
     */
    public static function get_cache_warmer_api_calls_per_run() {
        $options = get_option('flickr_justified_options', []);
        $max_calls = isset($options['cache_warmer_batch_size']) ? absint($options['cache_warmer_batch_size']) : 20;

        if ($max_calls < 1) {
            $max_calls = 1;
        } elseif ($max_calls > 200) {
            $max_calls = 200;
        }

        return $max_calls;
    }

    /**
     * Get default breakpoints
     */
    public static function get_default_breakpoints() {
        return [
            'mobile' => 320,           // Mobile Portrait
            'mobile_landscape' => 480, // Mobile Landscape
            'tablet_portrait' => 600,  // Tablet Portrait
            'tablet_landscape' => 768, // Tablet Landscape
            'desktop' => 1024,         // Desktop/Laptop
            'large_desktop' => 1280,   // Large Desktop
            'extra_large' => 1440      // Ultra-Wide Screens
        ];
    }

    /**
     * Get default responsive settings (images per row)
     */
    public static function get_default_responsive_settings() {
        return [
            'mobile' => 1,
            'mobile_landscape' => 1,
            'tablet_portrait' => 2,
            'tablet_landscape' => 3,
            'desktop' => 3,
            'large_desktop' => 4,
            'extra_large' => 4
        ];
    }

    /**
     * Get breakpoints from settings
     */
    public static function get_breakpoints() {
        $options = get_option('flickr_justified_options', []);
        $saved_breakpoints = isset($options['breakpoints']) ? $options['breakpoints'] : [];
        $default_breakpoints = self::get_default_breakpoints();

        // Merge with defaults and filter out empty values
        $breakpoints = [];
        foreach ($default_breakpoints as $key => $default_value) {
            if (isset($saved_breakpoints[$key]) && !empty($saved_breakpoints[$key])) {
                $breakpoints[$key] = absint($saved_breakpoints[$key]);
            } else {
                $breakpoints[$key] = $default_value;
            }
        }

        // Sort by pixel width (ascending)
        asort($breakpoints);

        return $breakpoints;
    }

    /**
     * Get default responsive settings from admin settings
     */
    public static function get_configured_default_responsive_settings() {
        $options = get_option('flickr_justified_options', []);
        $saved_responsive = isset($options['default_responsive_settings']) ? $options['default_responsive_settings'] : [];
        $default_responsive = self::get_default_responsive_settings();

        // Merge with defaults
        $responsive_settings = [];
        foreach ($default_responsive as $key => $default_value) {
            if (isset($saved_responsive[$key]) && is_numeric($saved_responsive[$key]) && $saved_responsive[$key] >= 1) {
                $responsive_settings[$key] = absint($saved_responsive[$key]);
            } else {
                $responsive_settings[$key] = $default_value;
            }
        }

        return $responsive_settings;
    }

    /**
     * Enqueue admin scripts
     */
    public static function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_flickr-justified-settings') {
            return;
        }

        $handle = 'flickr-justified-admin';
        $admin_js_path = FLICKR_JUSTIFIED_PLUGIN_PATH . 'assets/js/admin.js';
        $admin_js_ver  = @filemtime($admin_js_path);

        wp_enqueue_script(
            $handle,
            FLICKR_JUSTIFIED_PLUGIN_URL . 'assets/js/admin.js',
            [],
            $admin_js_ver ? $admin_js_ver : FLICKR_JUSTIFIED_VERSION,
            true
        );

        wp_localize_script($handle, 'FJGAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('test_flickr_api_key'),
        ]);
    }

    /**
     * Test API key via AJAX
     */
    public static function test_api_key_ajax() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'test_flickr_api_key')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $api_key = sanitize_text_field($_POST['api_key']);
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'API key is required']);
        }

        // If the API key looks masked (starts with asterisks), use the stored key instead
        if (preg_match('/^\*+[a-zA-Z0-9]+$/', $api_key)) {
            $api_key = self::get_api_key();
            if (empty($api_key)) {
                wp_send_json_error(['message' => 'No valid API key found in settings']);
            }
        }

        // Test the API key by making a simple API call
        $test_url = add_query_arg([
            'method' => 'flickr.test.echo',
            'api_key' => $api_key,
            'format' => 'json',
            'nojsoncallback' => 1,
        ], 'https://api.flickr.com/services/rest/');

        $response = wp_remote_get($test_url, [
            'timeout' => 10,
            'user-agent' => 'WordPress Flickr Justified Block'
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Connection failed: ' . $response->get_error_message()]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data)) {
            wp_send_json_error(['message' => 'Invalid response from Flickr API']);
        }

        if (isset($data['stat']) && $data['stat'] === 'ok') {
            wp_send_json_success(['message' => 'API key is valid and working!']);
        } elseif (isset($data['stat']) && $data['stat'] === 'fail') {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            wp_send_json_error(['message' => 'API key test failed: ' . $error_message]);
        } else {
            wp_send_json_error(['message' => 'Unexpected response from Flickr API']);
        }
    }

    /**
     * AJAX handler to rebuild known URLs from posts.
     */
    public static function ajax_rebuild_urls() {
        check_ajax_referer('flickr_warm_cache_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (!class_exists('FlickrJustifiedCacheWarmer')) {
            wp_send_json_error('Cache warmer not available');
        }

        $map = FlickrJustifiedCacheWarmer::rebuild_known_urls();
        $urls = [];
        foreach ($map as $post_urls) {
            if (is_array($post_urls)) {
                $urls = array_merge($urls, $post_urls);
            }
        }

        $unique_urls = array_values(array_unique($urls));
        wp_send_json_success(['queue' => $unique_urls, 'count' => count($unique_urls)]);
    }

    /**
     * AJAX handler to warm a batch of URLs.
     * Tracks API calls and detects rate limiting.
     */
    public static function ajax_warm_batch() {
        // Increase PHP execution time for large albums
        @set_time_limit(300); // 5 minutes max per batch

        check_ajax_referer('flickr_warm_cache_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (!class_exists('FlickrJustifiedCacheWarmer')) {
            wp_send_json_error('Cache warmer not available');
        }

        $urls = isset($_POST['urls']) ? $_POST['urls'] : [];
        if (empty($urls) || !is_array($urls)) {
            wp_send_json_error('No URLs provided');
        }

        // Delegate to cache.php for manual batch warming
        try {
            $result = FlickrJustifiedCache::warm_batch($urls);

            // Log any errors if WP_DEBUG is enabled
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flickr warm_batch result: ' . print_r($result, true));
            }

            wp_send_json_success($result);
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flickr warm_batch exception: ' . $e->getMessage());
            }
            wp_send_json_error('Error warming cache: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler to manually trigger the background queue processor
     */
    public static function ajax_process_queue() {
        check_ajax_referer('flickr_warm_cache_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        if (!class_exists('FlickrJustifiedCacheWarmer')) {
            wp_send_json_error('Cache warmer not available');
        }

        try {
            // Process the queue with pagination support
            $processed = FlickrJustifiedCacheWarmer::process_queue(false);

            wp_send_json_success([
                'processed' => $processed,
                'message' => sprintf('Processed %d item(s) from queue', $processed)
            ]);
        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Flickr ajax_process_queue exception: ' . $e->getMessage());
            }
            wp_send_json_error('Error processing queue: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler to clear cache for specific photo IDs
     */
    public static function ajax_clear_photo_cache() {
        check_ajax_referer('flickr_clear_photo_cache', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error([
                'message' => __('Insufficient permissions', 'flickr-justified-block')
            ]);
        }

        $photo_ids = isset($_POST['photo_ids']) ? sanitize_text_field($_POST['photo_ids']) : '';

        if (empty($photo_ids)) {
            wp_send_json_error([
                'message' => __('No photo IDs provided', 'flickr-justified-block')
            ]);
        }

        // Parse comma-separated photo IDs
        $photo_ids = array_map('trim', explode(',', $photo_ids));
        $photo_ids = array_filter($photo_ids, 'is_numeric');

        if (empty($photo_ids)) {
            wp_send_json_error([
                'message' => __('Invalid photo IDs provided', 'flickr-justified-block')
            ]);
        }

        global $wpdb;
        $cleared = [];
        $errors = [];

        foreach ($photo_ids as $photo_id) {
            try {
                // Delete transients for photo dimensions/sizes
                $deleted_dims = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->options}
                     WHERE option_name LIKE %s",
                    '%flickr_justified_dims_' . $photo_id . '%'
                ));

                // Delete transients for photo info
                $deleted_info = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->options}
                     WHERE option_name LIKE %s",
                    '%flickr_justified_photo_' . $photo_id . '%'
                ));

                // Delete transients for photo stats
                $deleted_stats = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->options}
                     WHERE option_name LIKE %s",
                    '%flickr_justified_stats_' . $photo_id . '%'
                ));

                // IMPORTANT: Also clear any album caches that might contain this photo
                // This ensures photos in albums get refreshed URLs
                $deleted_albums = $wpdb->query(
                    "DELETE FROM {$wpdb->options}
                     WHERE option_name LIKE '_transient_flickr_justified_set%'
                        OR option_name LIKE '_transient_timeout_flickr_justified_set%'"
                );

                $total_deleted = $deleted_dims + $deleted_info + $deleted_stats + $deleted_albums;

                if ($total_deleted > 0 || true) { // Always count as success even if no cache existed
                    $cleared[] = $photo_id;
                    self::log("Cleared cache for photo ID: {$photo_id} ({$total_deleted} cache entries removed, including album caches)");
                }
            } catch (Exception $e) {
                $errors[] = $photo_id;
                self::log("Error clearing cache for photo ID {$photo_id}: " . $e->getMessage());
            }
        }

        // Also flush WordPress object cache if available (Redis, Memcached, etc.)
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            self::log("Flushed WordPress object cache");
        }

        if (!empty($cleared)) {
            $message = sprintf(
                _n(
                    'Successfully cleared cache for photo %s. Refresh your page to see updated images.',
                    'Successfully cleared cache for %d photos: %s. Refresh your page to see updated images.',
                    count($cleared),
                    'flickr-justified-block'
                ),
                count($cleared),
                implode(', ', $cleared)
            );

            if (!empty($errors)) {
                $message .= ' ' . sprintf(
                    __('Failed to clear: %s', 'flickr-justified-block'),
                    implode(', ', $errors)
                );
            }

            wp_send_json_success([
                'message' => $message,
                'cleared' => $cleared,
                'errors' => $errors
            ]);
        } else {
            wp_send_json_error([
                'message' => __('Failed to clear cache for any photos', 'flickr-justified-block')
            ]);
        }
    }

    /**
     * AJAX handler to refresh a photo (clear cache and requeue)
     */
    public static function ajax_refresh_photo() {
        check_ajax_referer('fjb_refresh_photo', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Insufficient permissions', 'flickr-justified-block')
            ]);
        }

        $photo_id = isset($_POST['photo_id']) ? sanitize_text_field($_POST['photo_id']) : '';

        if (empty($photo_id) || !is_numeric($photo_id)) {
            wp_send_json_error([
                'message' => __('Invalid photo ID provided', 'flickr-justified-block')
            ]);
        }

        global $wpdb;
        $meta_table = $wpdb->prefix . 'fjb_photo_meta';
        $cache_table = $wpdb->prefix . 'fjb_photo_cache';
        $membership_table = $wpdb->prefix . 'fjb_membership';

        try {
            // 1. Clear cache and metadata
            $wpdb->delete($meta_table, ['photo_id' => $photo_id], ['%d']);
            $wpdb->delete($cache_table, ['photo_id' => $photo_id], ['%d']);
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                    OR option_name LIKE %s
                    OR option_name LIKE %s",
                '%flickr_justified_dims_' . $photo_id . '%',
                '%flickr_justified_photo_' . $photo_id . '%',
                '%flickr_justified_stats_' . $photo_id . '%'
            ));

            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }

            // 2. Queue photo for refetch (will get owner from API since we deleted metadata)
            $result = self::create_photo_job($photo_id, true);

            if (!$result['success']) {
                wp_send_json_error(['message' => $result['error']]);
                return;
            }

            $message = sprintf(
                __('Photo %s cache cleared and queued for refresh.', 'flickr-justified-block'),
                $photo_id
            );

            self::log("Refreshed photo ID: {$photo_id} ({$result['photo_url']})");

            wp_send_json_success([
                'message' => $message,
                'photo_id' => $photo_id,
                'requeued_items' => $requeued_items
            ]);
        } catch (Exception $e) {
            self::log("Error refreshing photo ID {$photo_id}: " . $e->getMessage());
            wp_send_json_error([
                'message' => sprintf(
                    __('Failed to refresh photo: %s', 'flickr-justified-block'),
                    $e->getMessage()
                )
            ]);
        }
    }

    /**
     * Helper function to create a photo job (used by refresh and queue)
     *
     * @param string $photo_id The photo ID
     * @param bool $fetch_from_api Whether to fetch owner from API if not in DB
     * @return array ['success' => bool, 'owner_nsid' => string, 'photo_url' => string, 'error' => string]
     */
    private static function create_photo_job($photo_id, $fetch_from_api = true) {
        global $wpdb;
        $meta_table = $wpdb->prefix . 'fjb_photo_meta';

        // Get photo metadata to build URL (if it exists)
        $photo_meta = $wpdb->get_row($wpdb->prepare(
            "SELECT meta_json FROM {$meta_table} WHERE photo_id = %d",
            $photo_id
        ));

        $owner_nsid = null;
        if ($photo_meta && !empty($photo_meta->meta_json)) {
            $meta_data = json_decode($photo_meta->meta_json, true);
            if (isset($meta_data['owner']['nsid'])) {
                $owner_nsid = $meta_data['owner']['nsid'];
            }
        }

        // If no metadata and fetch allowed, try API
        if (empty($owner_nsid) && $fetch_from_api) {
            if (class_exists('FlickrJustifiedCache')) {
                $photo_info = FlickrJustifiedCache::get_photo_info($photo_id);
                if (!empty($photo_info['owner']['nsid'])) {
                    $owner_nsid = $photo_info['owner']['nsid'];
                }
            }
        }

        if (empty($owner_nsid)) {
            return [
                'success' => false,
                'error' => __('Could not determine photo owner. Photo may not exist or be private.', 'flickr-justified-block')
            ];
        }

        // Build Flickr photo URL and create job
        $photo_url = 'https://www.flickr.com/photos/' . $owner_nsid . '/' . $photo_id . '/';
        $jobs_table = $wpdb->prefix . 'fjb_jobs';
        $job_key = 'photo:' . $photo_id;
        $payload = json_encode([
            'url' => $photo_url,
            'page' => 1
        ]);

        $wpdb->replace(
            $jobs_table,
            [
                'job_key' => $job_key,
                'job_type' => 'photo',
                'payload_json' => $payload,
                'priority' => 50,
                'status' => 'pending',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s']
        );

        return [
            'success' => true,
            'owner_nsid' => $owner_nsid,
            'photo_url' => $photo_url
        ];
    }

    /**
     * AJAX handler to manually queue a photo by ID
     */
    public static function ajax_queue_photo() {
        check_ajax_referer('fjb_queue_photo', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('Insufficient permissions', 'flickr-justified-block')
            ]);
        }

        $photo_id = isset($_POST['photo_id']) ? sanitize_text_field($_POST['photo_id']) : '';

        if (empty($photo_id) || !is_numeric($photo_id)) {
            wp_send_json_error([
                'message' => __('Invalid photo ID provided', 'flickr-justified-block')
            ]);
        }

        $result = self::create_photo_job($photo_id, true);

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['error']]);
            return;
        }

        self::log("Manually queued photo ID: {$photo_id} ({$result['photo_url']})");

        wp_send_json_success([
            'message' => sprintf(
                __('Photo %s queued successfully. It will be processed shortly.', 'flickr-justified-block'),
                $photo_id
            ),
            'photo_id' => $photo_id,
            'photo_url' => $result['photo_url']
        ]);
    }
}

// Initialize admin settings
FlickrJustifiedAdminSettings::init();

// Handle cache clearing
add_action('admin_init', [FlickrJustifiedAdminSettings::class, 'handle_cache_clear']);
