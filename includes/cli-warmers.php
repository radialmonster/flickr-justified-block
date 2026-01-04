<?php
/**
 * CLI helpers to warm cache and local meta storage.
 *
 * @package FlickrJustifiedBlock
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Warm a photoset by fetching a page and persisting meta locally.
 *
 * @param string $user_id
 * @param string $set_id
 * @param int $page
 * @param int $per_page
 * @return array Result payload.
 */
function flickr_justified_cli_warm_set($user_id, $set_id, $page = 1, $per_page = 50) {
    $result = FlickrJustifiedCache::get_photoset_photos($user_id, $set_id, $page, $per_page);
    $info = [
        'photos' => isset($result['photos']) ? count($result['photos']) : 0,
        'rate_limited' => !empty($result['rate_limited']),
        'has_more' => !empty($result['has_more']),
        'pages' => isset($result['pages']) ? (int) $result['pages'] : 1,
        'total' => isset($result['total']) ? (int) $result['total'] : 0,
    ];

    // Persist meta for each photo (best-effort)
    if (!empty($result['photos']) && is_array($result['photos'])) {
        foreach ($result['photos'] as $photo_url) {
            $photo_id = flickr_justified_extract_photo_id($photo_url);
            if ($photo_id) {
                // Attempt to pull info from cache; this will persist to DB if present
                FlickrJustifiedCache::get_photo_info($photo_id);
            }
        }
    }

    return $info;
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('fjb warm-set', function($args) {
        list($user_id, $set_id) = $args;
        $info = flickr_justified_cli_warm_set($user_id, $set_id, 1, 500);
        WP_CLI::success(sprintf('Warmed set %s/%s: photos=%d total=%d pages=%d has_more=%s rate_limited=%s',
            $user_id,
            $set_id,
            $info['photos'],
            $info['total'],
            $info['pages'],
            $info['has_more'] ? 'yes' : 'no',
            $info['rate_limited'] ? 'yes' : 'no'
        ));
    });
}
