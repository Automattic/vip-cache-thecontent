<?php
/**
 * Plugin Name:       [VIP] Post Content Cache
 * Plugin URI:        https://github.com/Automattic/vip-cache-thecontent
 * Description:       Caches the rendered `the_content` of a post, including its Gutenberg block-related CSS and JS dependencies.
 * Version:           1.0.0
 * Author:            Automattic
 * Author URI:        https://automattic.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Tested up to:      6.8
 * Requires PHP:      8.0
 */

// Main
namespace VIP_CacheTheContent_Plugin;

const CACHE_GROUP      = 'VIP';
const CACHE_TTL        = \HOUR_IN_SECONDS;
const CACHE_KEY_PREFIX = '_vip_ctc_';

require_once __DIR__ . '/includes/tools.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/hooks-post.php';
require_once __DIR__ . '/admin/admin.php';

add_action( 'init', function() {
    // Content request actions.
    if ( \VIP_CacheTheContent_Plugin\Tools\is_not_content_request() ) return;

    \VIP_CacheTheContent_Plugin\Hooks\Post\register();
});

