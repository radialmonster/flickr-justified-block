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
            'cache_warmer_enabled',
            __('Preload Flickr Data', 'flickr-justified-block'),
            [__CLASS__, 'cache_warmer_enabled_callback'],
            'flickr_justified_settings',
            'flickr_justified_cache_section'
        );

        add_settings_field(
            'cache_warmer_batch_size',
            __('Cache Warmer Batch Size', 'flickr-justified-block'),
            [__CLASS__, 'cache_warmer_batch_size_callback'],
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

    /**
     * Cache warmer toggle callback
     */
    public static function cache_warmer_enabled_callback() {
        $options = get_option('flickr_justified_options', []);
        $enabled = array_key_exists('cache_warmer_enabled', $options) ? (bool) $options['cache_warmer_enabled'] : true;
        $slow_mode = array_key_exists('cache_warmer_slow_mode', $options) ? (bool) $options['cache_warmer_slow_mode'] : true;

        echo '<label>';
        echo '<input type="checkbox" name="flickr_justified_options[cache_warmer_enabled]" value="1" ' . checked($enabled, true, false) . ' /> ';
        echo esc_html__('Warm Flickr responses automatically in the background (WP-Cron).', 'flickr-justified-block');
        echo '</label>';

        echo '<br />';
        echo '<label style="margin-top:6px; display:inline-block;">';
        echo '<input type="checkbox" name="flickr_justified_options[cache_warmer_slow_mode]" value="1" ' . checked($slow_mode, true, false) . ' /> ';
        echo esc_html__('Slow mode: process a small batch every few minutes so editors and visitors are unaffected.', 'flickr-justified-block');
        echo '</label>';

        $cache_duration_hours = max(1, (int) round(self::get_cache_duration() / HOUR_IN_SECONDS));
        echo '<p class="description">' . sprintf(esc_html__('Prefetched API responses honour the Cache Duration above (currently %d hour(s)).', 'flickr-justified-block'), $cache_duration_hours) . '</p>';
        echo '<p class="description">' . __('Run immediately with <code>wp flickr-justified warm-cache</code> or schedule via WP-Cron.', 'flickr-justified-block') . '</p>';
    }

    /**
     * Cache warmer batch size callback
     */
    public static function cache_warmer_batch_size_callback() {
        $batch_size = self::get_cache_warmer_batch_size();

        echo '<input type="number" name="flickr_justified_options[cache_warmer_batch_size]" value="' . esc_attr($batch_size) . '" min="1" max="25" class="small-text" />';
        echo ' ' . esc_html__('URLs per batch', 'flickr-justified-block');
        echo '<p class="description">' . esc_html__('Lower numbers keep the warmer lightweight; increase if you have many galleries and want faster priming.', 'flickr-justified-block') . '</p>';
    }

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

            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('Cache Management', 'flickr-justified-block'); ?></h2>

                <h3><?php _e('Warm Cache', 'flickr-justified-block'); ?></h3>
                <p><?php _e('Pre-fetch Flickr data for all your posts to make pages load faster. Run this after adding new posts or if pages are loading slowly.', 'flickr-justified-block'); ?></p>
                <p style="font-size: 12px; color: #666;"><strong><?php _e('Note:', 'flickr-justified-block'); ?></strong> <?php _e('Flickr API has a rate limit of 3600 calls per hour. If you see "Rate limit detected" immediately, you may have hit this limit from previous warming attempts. Wait an hour and try again.', 'flickr-justified-block'); ?></p>
                <p>
                    <button type="button" id="flickr-warm-cache-btn" class="button button-primary"><?php _e('Warm Cache Now', 'flickr-justified-block'); ?></button>
                    <button type="button" id="flickr-process-queue-btn" class="button" style="margin-left: 10px;"><?php _e('Process Queue (with Pagination)', 'flickr-justified-block'); ?></button>
                </p>
                <p style="font-size: 12px; color: #666;">
                    <em><?php _e('Use "Process Queue" to test the background pagination processor. This processes items from the queue and automatically queues additional pages for large albums.', 'flickr-justified-block'); ?></em>
                </p>
                <div id="flickr-warm-cache-progress" style="display: none; margin-top: 10px;">
                    <div style="background: #f0f0f1; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px;">
                        <p id="flickr-warm-cache-status"><?php _e('Initializing...', 'flickr-justified-block'); ?></p>
                        <div style="background: #fff; height: 30px; border: 1px solid #c3c4c7; border-radius: 4px; overflow: hidden; margin: 10px 0;">
                            <div id="flickr-warm-cache-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>
                        </div>
                        <p id="flickr-warm-cache-details" style="font-size: 12px; color: #666;"></p>
                    </div>
                </div>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const warmCacheBtn = document.getElementById('flickr-warm-cache-btn');
                    if (!warmCacheBtn) return;

                    warmCacheBtn.addEventListener('click', function() {
                        const btn = this;
                        const progress = document.getElementById('flickr-warm-cache-progress');
                        const status = document.getElementById('flickr-warm-cache-status');
                        const details = document.getElementById('flickr-warm-cache-details');
                        const bar = document.getElementById('flickr-warm-cache-bar');

                        btn.disabled = true;
                        btn.textContent = '<?php esc_attr_e('Processing...', 'flickr-justified-block'); ?>';
                        progress.style.display = 'block';
                        status.textContent = '<?php esc_js(_e('Scanning posts for Flickr URLs...', 'flickr-justified-block')); ?>';

                        const initialFormData = new URLSearchParams();
                        initialFormData.append('action', 'flickr_rebuild_urls');
                        initialFormData.append('nonce', '<?php echo wp_create_nonce('flickr_warm_cache_ajax'); ?>');

                        fetch(ajaxurl, {
                            method: 'POST',
                            body: initialFormData
                        })
                        .then(response => {
                            if (!response.ok) throw new Error('Network response was not ok for rebuilding URLs.');
                            return response.json();
                        })
                        .then(response => {
                            if (response.success) {
                                const queue = response.data.queue;
                                const totalUrls = queue.length;
                                let processed = 0;
                                const batchSize = 1;
                                let totalApiCalls = 0;

                                if (totalUrls === 0) {
                                    status.textContent = '<?php esc_js(_e('No Flickr URLs found to warm.', 'flickr-justified-block')); ?>';
                                    btn.disabled = false;
                                    btn.textContent = '<?php esc_attr_e('Warm Cache Now', 'flickr-justified-block'); ?>';
                                    progress.style.display = 'none';
                                    return;
                                }

                                status.textContent = '<?php esc_js(_e('Found', 'flickr-justified-block')); ?> ' + totalUrls + ' <?php esc_js(_e('URLs. Warming cache...', 'flickr-justified-block')); ?>';

                                function processBatch(startIndex, retryCount = 0) {
                                    if (startIndex >= totalUrls) {
                                        bar.style.width = '100%';
                                        status.innerHTML = '<strong style="color: #00a32a;">✓ <?php esc_js(_e('Complete!', 'flickr-justified-block')); ?></strong>';
                                        details.textContent = '<?php esc_js(_e('Warmed', 'flickr-justified-block')); ?> ' + processed + ' / ' + totalUrls + ' <?php esc_js(_e('URLs', 'flickr-justified-block')); ?> (' + totalApiCalls + ' <?php esc_js(_e('API calls). Pages should now load much faster!', 'flickr-justified-block')); ?>';
                                        btn.disabled = false;
                                        btn.textContent = '<?php esc_attr_e('Warm Cache Now', 'flickr-justified-block'); ?>';
                                        return;
                                    }

                                    const batch = queue.slice(startIndex, startIndex + batchSize);
                                    const batchFormData = new URLSearchParams();
                                    batchFormData.append('action', 'flickr_warm_batch');
                                    batch.forEach(url => batchFormData.append('urls[]', url));
                                    batchFormData.append('nonce', '<?php echo wp_create_nonce('flickr_warm_cache_ajax'); ?>');

                                    const controller = new AbortController();
                                    const timeoutId = setTimeout(() => controller.abort(), 300000);

                                    fetch(ajaxurl, {
                                        method: 'POST',
                                        body: batchFormData,
                                        signal: controller.signal
                                    })
                                    .then(res => {
                                        clearTimeout(timeoutId);
                                        if (!res.ok) throw new Error(`HTTP error! Status: ${res.status}`);
                                        return res.json();
                                    })
                                    .then(batchResponse => {
                                        if (batchResponse.success) {
                                            const data = batchResponse.data;
                                            processed += data.processed;
                                            totalApiCalls += (data.api_calls || 0);

                                            const percent = Math.round((startIndex + batch.length) / totalUrls * 100);
                                            bar.style.width = percent + '%';

                                            if (data.rate_limited) {
                                                const diagnosticMsg = data.diagnostic ? ' ' + data.diagnostic : '';
                                                const pauseSeconds = 60;
                                                const pauseMinutes = Math.round(pauseSeconds / 60);
                                                status.innerHTML = '⏸ <?php esc_js(_e('Rate limit detected. Will retry in', 'flickr-justified-block')); ?> ' + pauseMinutes + ' <?php esc_js(_e('minute(s)...', 'flickr-justified-block')); ?>' + diagnosticMsg;
                                                details.innerHTML = '<?php esc_js(_e('Processed', 'flickr-justified-block')); ?> ' + processed + ' / ' + totalUrls + ' <?php esc_js(_e('URLs', 'flickr-justified-block')); ?> (' + totalApiCalls + ' <?php esc_js(_e('API calls)', 'flickr-justified-block')); ?>).<br>' +
                                                    '<em style="color: #666;"><?php esc_js(_e('Manual warming will retry automatically. You can close this page - the automatic background warmer will continue.', 'flickr-justified-block')); ?></em>';

                                                setTimeout(() => {
                                                    status.textContent = '<?php esc_js(_e('Resuming...', 'flickr-justified-block')); ?>';
                                                    processBatch(startIndex, retryCount + 1);
                                                }, pauseSeconds * 1000);
                                            } else {
                                                details.textContent = '<?php esc_js(_e('Processed', 'flickr-justified-block')); ?> ' + processed + ' / ' + totalUrls + ' <?php esc_js(_e('URLs', 'flickr-justified-block')); ?> (' + totalApiCalls + ' <?php esc_js(_e('API calls)', 'flickr-justified-block')); ?>';
                                                processBatch(startIndex + batchSize, 0);
                                            }
                                        } else {
                                            throw new Error(batchResponse.data || '<?php esc_js(_e('Unknown error during batch processing.', 'flickr-justified-block')); ?>');
                                        }
                                    })
                                    .catch(err => {
                                        clearTimeout(timeoutId);
                                        let errorMsg = '<?php esc_js(_e('Network error', 'flickr-justified-block')); ?>';
                                        if (err.name === 'AbortError') {
                                            errorMsg = '<?php esc_js(_e('Request timed out. Large albums may take several minutes.', 'flickr-justified-block')); ?>';
                                        } else if(err.message) {
                                            errorMsg = err.message;
                                        }
                                        status.innerHTML = `<strong style="color: #d63638;">✗ ${errorMsg}</strong>`;

                                        if (processed > 0) {
                                            details.innerHTML = '<?php esc_js(_e('Processed', 'flickr-justified-block')); ?> ' + processed + ' / ' + totalUrls + ' <?php esc_js(_e('URLs', 'flickr-justified-block')); ?> (' + totalApiCalls + ' <?php esc_js(_e('API calls)', 'flickr-justified-block')); ?>).<br>' +
                                                '<em style="color: #666;"><?php esc_js(_e('The automatic background warmer will continue processing remaining URLs.', 'flickr-justified-block')); ?></em>';
                                        }
                                        btn.disabled = false;
                                        btn.textContent = '<?php esc_attr_e('Warm Cache Now', 'flickr-justified-block'); ?>';
                                    });
                                }
                                processBatch(0);
                            } else {
                                throw new Error(response.data || '<?php esc_js(_e('Could not scan posts.', 'flickr-justified-block')); ?>');
                            }
                        })
                        .catch(error => {
                            status.innerHTML = `<strong style="color: #d63638;">✗ ${error.message}</strong>`;
                            btn.disabled = false;
                            btn.textContent = '<?php esc_attr_e('Warm Cache Now', 'flickr-justified-block'); ?>';
                        });
                    });

                    // Process Queue button handler
                    const processQueueBtn = document.getElementById('flickr-process-queue-btn');
                    if (processQueueBtn) {
                        processQueueBtn.addEventListener('click', function() {
                            const btn = this;
                            const progress = document.getElementById('flickr-warm-cache-progress');
                            const status = document.getElementById('flickr-warm-cache-status');

                            btn.disabled = true;
                            btn.textContent = '<?php esc_attr_e('Processing...', 'flickr-justified-block'); ?>';
                            progress.style.display = 'block';
                            status.textContent = '<?php esc_js(_e('Running background queue processor...', 'flickr-justified-block')); ?>';

                            const formData = new URLSearchParams();
                            formData.append('action', 'flickr_process_queue');
                            formData.append('nonce', '<?php echo wp_create_nonce('flickr_warm_cache_ajax'); ?>');

                            fetch(ajaxurl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                body: formData
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    status.innerHTML = '<strong style="color: #00a32a;">✓ ' + data.data.message + '</strong>';
                                } else {
                                    status.innerHTML = '<strong style="color: #d63638;">✗ Error: ' + data.data + '</strong>';
                                }
                                btn.disabled = false;
                                btn.textContent = '<?php esc_attr_e('Process Queue (with Pagination)', 'flickr-justified-block'); ?>';
                            })
                            .catch(error => {
                                status.innerHTML = '<strong style="color: #d63638;">✗ ' + error.message + '</strong>';
                                btn.disabled = false;
                                btn.textContent = '<?php esc_attr_e('Process Queue (with Pagination)', 'flickr-justified-block'); ?>';
                            });
                        });
                    }
                });
                </script>

                <h3><?php _e('Clear Cache', 'flickr-justified-block'); ?></h3>
                <p><?php _e('If you\'re experiencing issues with images not updating, you can clear the cached Flickr data.', 'flickr-justified-block'); ?></p>
                <form method="post" action="">
                    <?php wp_nonce_field('flickr_justified_clear_cache', 'flickr_justified_clear_cache_nonce'); ?>
                    <input type="hidden" name="action" value="clear_flickr_cache" />
                    <?php submit_button(__('Clear All Flickr Cache', 'flickr-justified-block'), 'secondary', 'clear_cache', false); ?>
                </form>

                <hr style="margin: 30px 0;">

                <h3><?php _e('Clear Individual Photo Cache', 'flickr-justified-block'); ?></h3>
                <p><?php _e('If a specific photo isn\'t displaying correctly (e.g., showing 410 Gone error), clear just that photo\'s cache. This is useful when Flickr migrates old photos to new servers.', 'flickr-justified-block'); ?></p>
                <p style="font-size: 12px; color: #666;">
                    <strong><?php _e('Accepted formats:', 'flickr-justified-block'); ?></strong><br>
                    • <?php _e('Photo IDs:', 'flickr-justified-block'); ?> <code>132149878</code><br>
                    • <?php _e('Photo page URLs:', 'flickr-justified-block'); ?> <code>https://www.flickr.com/photos/username/132149878/</code><br>
                    • <?php _e('Image URLs:', 'flickr-justified-block'); ?> <code>https://live.staticflickr.com/103/276208727_02aefaf69f_o.jpg</code><br>
                    • <?php _e('Multiple entries (comma or newline separated)', 'flickr-justified-block'); ?>
                </p>
                <div style="margin-top: 15px;">
                    <textarea id="flickr-refresh-photo-ids" placeholder="<?php esc_attr_e('Paste Photo IDs, photo URLs, or image URLs (one per line or comma-separated)', 'flickr-justified-block'); ?>" style="width: 100%; max-width: 500px; padding: 8px; min-height: 80px; font-family: monospace; font-size: 13px;"></textarea>
                    <button type="button" id="flickr-refresh-photos-btn" class="button button-secondary" style="margin-top: 10px;">
                        <?php _e('Clear Photo Cache', 'flickr-justified-block'); ?>
                    </button>
                    <div id="flickr-refresh-result" style="margin-top: 15px;"></div>
                </div>

                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        // Extract photo ID from various Flickr URL formats
                        function extractPhotoId(input) {
                            input = input.trim();
                            if (!input) return null;

                            // Already a numeric ID
                            if (/^\d+$/.test(input)) {
                                return input;
                            }

                            // Photo page URL: https://www.flickr.com/photos/username/132149878/
                            let match = input.match(/flickr\.com\/photos\/[^\/]+\/(\d+)/i);
                            if (match) return match[1];

                            // Image URL: https://live.staticflickr.com/103/276208727_02aefaf69f_o.jpg
                            // or: https://farm{n}.staticflickr.com/103/276208727_02aefaf69f_o.jpg
                            match = input.match(/staticflickr\.com\/\d+\/(\d+)_/i);
                            if (match) return match[1];

                            return null;
                        }

                        $('#flickr-refresh-photos-btn').on('click', function() {
                            const button = $(this);
                            const textarea = $('#flickr-refresh-photo-ids');
                            const resultDiv = $('#flickr-refresh-result');
                            const rawInput = textarea.val().trim();

                            if (!rawInput) {
                                resultDiv.html('<div class="notice notice-error inline"><p><?php esc_html_e('Please enter at least one Photo ID or URL.', 'flickr-justified-block'); ?></p></div>');
                                return;
                            }

                            // Split by newlines and commas
                            const lines = rawInput.split(/[\r\n,]+/);
                            const photoIds = [];
                            const failed = [];

                            lines.forEach(function(line) {
                                const id = extractPhotoId(line);
                                if (id) {
                                    photoIds.push(id);
                                } else if (line.trim()) {
                                    failed.push(line.trim());
                                }
                            });

                            if (photoIds.length === 0) {
                                resultDiv.html('<div class="notice notice-error inline"><p><?php esc_html_e('Could not extract any valid Photo IDs. Please check your input.', 'flickr-justified-block'); ?></p></div>');
                                return;
                            }

                            if (failed.length > 0) {
                                resultDiv.html('<div class="notice notice-warning inline"><p><?php esc_html_e('Warning: Could not extract Photo IDs from some entries:', 'flickr-justified-block'); ?> ' + failed.join(', ') + '</p></div>');
                            }

                            button.prop('disabled', true);
                            button.text('<?php esc_html_e('Clearing...', 'flickr-justified-block'); ?>');

                            // Show what IDs were extracted
                            const extractedMsg = '<?php esc_html_e('Extracted Photo IDs:', 'flickr-justified-block'); ?> ' + photoIds.join(', ');
                            resultDiv.html('<p>' + extractedMsg + '<br><?php esc_html_e('Clearing cache...', 'flickr-justified-block'); ?></p>');

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'flickr_clear_photo_cache',
                                    nonce: '<?php echo wp_create_nonce('flickr_clear_photo_cache'); ?>',
                                    photo_ids: photoIds.join(',')
                                },
                                success: function(response) {
                                    if (response.success) {
                                        resultDiv.html('<div class="notice notice-success inline"><p><strong><?php esc_html_e('Success!', 'flickr-justified-block'); ?></strong> ' + response.data.message + '</p></div>');
                                        textarea.val('');
                                    } else {
                                        resultDiv.html('<div class="notice notice-error inline"><p><strong><?php esc_html_e('Error:', 'flickr-justified-block'); ?></strong> ' + response.data.message + '</p></div>');
                                    }
                                },
                                error: function() {
                                    resultDiv.html('<div class="notice notice-error inline"><p><?php esc_html_e('An error occurred. Please try again.', 'flickr-justified-block'); ?></p></div>');
                                },
                                complete: function() {
                                    button.prop('disabled', false);
                                    button.text('<?php esc_html_e('Clear Photo Cache', 'flickr-justified-block'); ?>');
                                }
                            });
                        });

                        // Allow pressing Ctrl+Enter (or Cmd+Enter on Mac) to submit
                        $('#flickr-refresh-photo-ids').on('keydown', function(e) {
                            if ((e.ctrlKey || e.metaKey) && e.which === 13) {
                                e.preventDefault();
                                $('#flickr-refresh-photos-btn').click();
                            }
                        });
                    });
                </script>
            </div>

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
    public static function get_cache_warmer_batch_size() {
        $options = get_option('flickr_justified_options', []);
        $batch_size = isset($options['cache_warmer_batch_size']) ? absint($options['cache_warmer_batch_size']) : 5;

        if ($batch_size < 1) {
            $batch_size = 1;
        } elseif ($batch_size > 25) {
            $batch_size = 25;
        }

        return $batch_size;
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

                $total_deleted = $deleted_dims + $deleted_info + $deleted_stats;

                if ($total_deleted > 0 || true) { // Always count as success even if no cache existed
                    $cleared[] = $photo_id;
                    self::log("Cleared cache for photo ID: {$photo_id} ({$total_deleted} cache entries removed)");
                }
            } catch (Exception $e) {
                $errors[] = $photo_id;
                self::log("Error clearing cache for photo ID {$photo_id}: " . $e->getMessage());
            }
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
}

// Initialize admin settings
FlickrJustifiedAdminSettings::init();

// Handle cache clearing
add_action('admin_init', [FlickrJustifiedAdminSettings::class, 'handle_cache_clear']);