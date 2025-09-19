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

        // Sanitize breakpoints
        if (isset($input['breakpoints']) && is_array($input['breakpoints'])) {
            $sanitized['breakpoints'] = [];
            foreach ($input['breakpoints'] as $key => $value) {
                if (is_numeric($value)) {
                    $sanitized['breakpoints'][$key] = max(200, min(3000, absint($value))); // Clamp between 200-3000px
                }
            }
        }

        return $sanitized;
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
            echo '<p class="description" style="color: #46b450;">✓ ' . __('API key configured', 'flickr-justified-block') . '</p>';
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

        echo '<table class="form-table">';
        echo '<tbody>';

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
            $value = isset($breakpoints[$key]) ? $breakpoints[$key] : '';
            echo '<tr>';
            echo '<td style="width: 200px;"><strong>' . esc_html($label) . '</strong></td>';
            echo '<td>';
            echo '<input type="number" name="flickr_justified_options[breakpoints][' . esc_attr($key) . ']" value="' . esc_attr($value) . '" min="200" max="3000" class="small-text" placeholder="px" /> px';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        echo '<p class="description">' . __('Set the minimum width in pixels for each device category. Leave empty to disable a breakpoint.', 'flickr-justified-block') . '</p>';
        echo '<p class="description"><strong>' . __('Common sizes:', 'flickr-justified-block') . '</strong> Mobile: 320-480px, Tablet: 768-1024px, Desktop: 1280-1440px, Ultra-wide: 1920px+</p>';

        echo '<p><button type="button" id="reset-breakpoints" class="button button-secondary">' . __('Reset to Defaults', 'flickr-justified-block') . '</button></p>';

        // Add JavaScript for reset functionality
        ?>
        <script>
        document.getElementById('reset-breakpoints').addEventListener('click', function() {
            var defaults = <?php echo json_encode(self::get_default_breakpoints()); ?>;
            for (var key in defaults) {
                var input = document.querySelector('input[name="flickr_justified_options[breakpoints][' + key + ']"]');
                if (input) {
                    input.value = defaults[key];
                }
            }
        });
        </script>
        <?php
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
                    <?php _e('Add the "Flickr Justified" block to any post or page, then paste image URLs (one per line) in the block settings.', 'flickr-justified-block'); ?>
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
                <h2><?php _e('Clear Cache', 'flickr-justified-block'); ?></h2>
                <p><?php _e('If you\'re experiencing issues with images not updating, you can clear the cached Flickr data.', 'flickr-justified-block'); ?></p>
                <form method="post" action="">
                    <?php wp_nonce_field('flickr_justified_clear_cache', 'flickr_justified_clear_cache_nonce'); ?>
                    <input type="hidden" name="action" value="clear_flickr_cache" />
                    <?php submit_button(__('Clear Flickr Cache', 'flickr-justified-block'), 'secondary', 'clear_cache', false); ?>
                </form>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2><?php _e('Supported URL Formats', 'flickr-justified-block'); ?></h2>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><strong><?php _e('Flickr Photo Pages:', 'flickr-justified-block'); ?></strong> https://www.flickr.com/photos/username/1234567890/</li>
                    <li><strong><?php _e('Direct Images:', 'flickr-justified-block'); ?></strong> https://example.com/image.jpg</li>
                    <li><strong><?php _e('Supported Formats:', 'flickr-justified-block'); ?></strong> JPG, PNG, WebP, AVIF, GIF, SVG</li>
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

            // Clear all transients that start with our prefix
            global $wpdb;
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    '_transient_flickr_justified_%'
                )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    '_transient_timeout_flickr_justified_%'
                )
            );

            wp_redirect(add_query_arg(['page' => 'flickr-justified-settings', 'cache-cleared' => '1'], admin_url('options-general.php')));
            exit;
        }

        if (isset($_GET['cache-cleared'])) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Flickr cache cleared successfully!', 'flickr-justified-block') . '</p></div>';
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
            error_log('Flickr Justified Block: No encrypted API key found in options');
            return '';
        }

        // Try to decrypt the API key
        $decrypted_key = self::decrypt_api_key($encrypted_api_key);

        // If decryption fails, the key might be stored in plain text (legacy)
        if (empty($decrypted_key)) {
            error_log('Flickr Justified Block: Decryption failed, checking if legacy plain text key');
            // Check if it looks like a plain text API key (not base64 encrypted)
            if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $encrypted_api_key)) {
                error_log('Flickr Justified Block: Using legacy plain text API key');
                return trim($encrypted_api_key); // Return as-is for legacy compatibility
            }
            error_log('Flickr Justified Block: API key looks encrypted but decryption failed');
            return '';
        }

        error_log('Flickr Justified Block: Successfully decrypted API key, length: ' . strlen($decrypted_key));
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
     * Enqueue admin scripts
     */
    public static function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_flickr-justified-settings') {
            return;
        }

        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                $("#test-api-key").on("click", function() {
                    var button = $(this);
                    var apiKey = $("#flickr-api-key-input").val().trim();
                    var resultDiv = $("#api-test-result");

                    if (!apiKey) {
                        resultDiv.html("<div class=\"notice notice-error inline\"><p>Please enter an API key to test.</p></div>");
                        return;
                    }

                    // Show loading state
                    button.prop("disabled", true).text("Testing...");
                    resultDiv.html("<div class=\"notice notice-info inline\"><p>Testing API key...</p></div>");

                    $.ajax({
                        url: ajaxurl,
                        method: "POST",
                        data: {
                            action: "test_flickr_api_key",
                            api_key: apiKey,
                            nonce: "' . wp_create_nonce('test_flickr_api_key') . '"
                        },
                        success: function(response) {
                            if (response.success) {
                                resultDiv.html("<div class=\"notice notice-success inline\"><p>✓ " + response.data.message + "</p></div>");
                            } else {
                                resultDiv.html("<div class=\"notice notice-error inline\"><p>✗ " + response.data.message + "</p></div>");
                            }
                        },
                        error: function() {
                            resultDiv.html("<div class=\"notice notice-error inline\"><p>✗ Failed to test API key. Please try again.</p></div>");
                        },
                        complete: function() {
                            button.prop("disabled", false).text("Test API Key");
                        }
                    });
                });
            });
        ');
    }

    /**
     * Test API key via AJAX
     */
    public static function test_api_key_ajax() {
        if (!wp_verify_nonce($_POST['nonce'], 'test_flickr_api_key')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $api_key = sanitize_text_field($_POST['api_key']);
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'API key is required']);
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
}

// Initialize admin settings
FlickrJustifiedAdminSettings::init();

// Handle cache clearing
add_action('admin_init', [FlickrJustifiedAdminSettings::class, 'handle_cache_clear']);