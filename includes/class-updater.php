<?php
/**
 * GitHub Releases auto-updater for Flickr Justified Block.
 *
 * @package FlickrJustifiedBlock
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Flickr_Justified_Block_Updater {

    const GITHUB_REPO = 'radialmonster/flickr-justified-block';
    const SLUG        = 'flickr-justified-block';
    const CACHE_KEY   = 'flickr_justified_block_github_release';
    const CACHE_TTL   = 12 * HOUR_IN_SECONDS;

    public static function init() {
        add_filter( 'update_plugins_github.com', array( __CLASS__, 'check_update' ), 10, 4 );
        add_filter( 'upgrader_install_package_result', array( __CLASS__, 'fix_directory' ), 10, 2 );
        add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
        add_action( 'admin_post_flickr_justified_block_check_updates', array( __CLASS__, 'handle_check_updates' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( FLICKR_JUSTIFIED_PLUGIN_FILE ), array( __CLASS__, 'action_link' ) );
    }

    public static function check_update( $update, $plugin_data, $plugin_file, $locales ) {
        if ( plugin_basename( FLICKR_JUSTIFIED_PLUGIN_FILE ) !== $plugin_file ) {
            return $update;
        }

        $release = self::fetch_latest_release();
        if ( ! $release ) {
            return $update;
        }

        $remote_version = ltrim( $release->tag_name, 'v' );

        if ( version_compare( FLICKR_JUSTIFIED_VERSION, $remote_version, '>=' ) ) {
            return $update;
        }

        return array(
            'slug'    => self::SLUG,
            'version' => $remote_version,
            'url'     => $release->html_url,
            'package' => self::get_asset_url( $release ),
        );
    }

    public static function fix_directory( $result, $options ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        if ( ! isset( $options['plugin'] ) || plugin_basename( FLICKR_JUSTIFIED_PLUGIN_FILE ) !== $options['plugin'] ) {
            return $result;
        }

        global $wp_filesystem;

        $expected_dir = trailingslashit( WP_PLUGIN_DIR ) . self::SLUG;
        $actual_dir   = isset( $result['destination'] ) ? rtrim( $result['destination'], '/' ) : '';

        if ( $actual_dir === $expected_dir ) {
            return $result;
        }

        if ( $wp_filesystem && $wp_filesystem->move( $actual_dir, $expected_dir, true ) ) {
            $result['destination']        = $expected_dir;
            $result['destination_name']   = self::SLUG;
            $result['remote_destination'] = $expected_dir;
        }

        return $result;
    }

    public static function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
            return $result;
        }

        $release = self::fetch_latest_release();
        if ( ! $release ) {
            return $result;
        }

        $remote_version = ltrim( $release->tag_name, 'v' );

        $info                = new stdClass();
        $info->name          = 'Flickr Justified Block';
        $info->slug          = self::SLUG;
        $info->version       = $remote_version;
        $info->author        = '<a href="https://github.com/radialmonster">RadialMonster</a>';
        $info->homepage      = 'https://github.com/' . self::GITHUB_REPO;
        $info->requires      = '6.7';
        $info->requires_php  = '8.1';
        $info->download_link = self::get_asset_url( $release );
        $info->sections      = array(
            'description' => 'A WordPress block that displays Flickr photos and other images in a responsive justified gallery layout.',
            'changelog'   => nl2br( esc_html( $release->body ?? '' ) ),
        );

        return $info;
    }

    public static function handle_check_updates() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        check_admin_referer( 'flickr_justified_block_check_updates' );

        delete_transient( self::CACHE_KEY );
        wp_clean_plugins_cache( true );
        wp_update_plugins();

        $release        = self::fetch_latest_release();
        $remote_version = $release ? ltrim( $release->tag_name, 'v' ) : '';
        $has_update     = $release && version_compare( FLICKR_JUSTIFIED_VERSION, $remote_version, '<' );

        wp_safe_redirect( add_query_arg(
            array(
                'page'           => 'flickr-justified-settings',
                'update_check'   => '1',
                'has_update'     => $has_update ? '1' : '0',
                'remote_version' => rawurlencode( $remote_version ),
            ),
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    public static function action_link( $links ) {
        $url  = wp_nonce_url(
            admin_url( 'admin-post.php?action=flickr_justified_block_check_updates' ),
            'flickr_justified_block_check_updates'
        );
        $link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for Updates', 'flickr-justified-block' ) . '</a>';
        array_unshift( $links, $link );
        return $links;
    }

    private static function get_asset_url( $release ) {
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( '.zip' === substr( $asset->name, -4 ) ) {
                    return $asset->browser_download_url;
                }
            }
        }
        return $release->zipball_url;
    }

    private static function fetch_latest_release() {
        $force = isset( $_GET['force-check'] ) || ( defined( 'DOING_CRON' ) && DOING_CRON );
        if ( ! $force ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( false !== $cached ) {
                return 'error' === $cached ? false : $cached;
            }
        }

        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            set_transient( self::CACHE_KEY, 'error', 5 * MINUTE_IN_SECONDS );
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ) );
        if ( ! $release || empty( $release->tag_name ) ) {
            set_transient( self::CACHE_KEY, 'error', 5 * MINUTE_IN_SECONDS );
            return false;
        }

        set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

        return $release;
    }
}
