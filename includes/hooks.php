<?php
/**
 * Front-end hook registration and callbacks.
 *
 * @package VIP_CacheTheContent_Plugin
 */

namespace VIP_CacheTheContent_Plugin\Hooks;

/**
 * Registers front-end hooks for caching and loading post content during template rendering.
 */
function register() {
    \add_action( 'template_redirect',
        __NAMESPACE__ . '\\maybe_cache_post_content', 991 );

    \add_action( 'template_redirect',
        __NAMESPACE__ . '\\register_content_loader', 992 );

    // Restore cached Style Engine CSS early (before wp_head finishes)
    \add_action( 'wp_enqueue_scripts',
        __NAMESPACE__ . '\\restore_cached_style_engine_css', 100 );
}

/**
 * Restores cached Style Engine CSS on cache hit.
 * Runs on wp_enqueue_scripts to ensure CSS appears in <head>.
 */
function restore_cached_style_engine_css() {
    if ( ! \is_singular( get_posttypes() ) ) return;

    global $post;
    if ( ! ( $post instanceof \WP_Post ) ) return;

    // Only restore if loading from object cache (not just generated this request)
    $mem =& \VIP_CacheTheContent_Plugin\Cache\_local_store();
    if ( isset( $mem[ $post->ID ] ) ) return; // Generated this request, Style Engine handles it

    $cached = \VIP_CacheTheContent_Plugin\Cache\get( $post->ID );
    if ( empty( $cached['style_engine_css'] ) ) return;

    // Output Style Engine CSS via wp_head
    $css = $cached['style_engine_css'];
    \add_action( 'wp_head', function() use ( $css ) {
        echo "<style id='vip-cached-block-supports'>$css</style>\n";
    }, 999 );
}

/**
 * Ensures the current post's content is cached, if it matches allowed post types and is not already cached.
 */
function maybe_cache_post_content() {
    if ( ! \is_singular( get_posttypes() ) ) return;

    global $post;
    if ( ! ( $post instanceof \WP_Post ) ) return;
    if ( ! \has_blocks( $post ) ) return;

    $cached = \VIP_CacheTheContent_Plugin\Cache\get( $post->ID );
    if ( empty( $cached ) ) \VIP_CacheTheContent_Plugin\Cache\set( $post->ID );
}

/**
 * Attaches the content-loading filter to `the_content` to replace it with cached output and enqueue assets.
 */
function register_content_loader() {
    if ( ! \is_singular( get_posttypes() ) ) return;

    \add_filter( 'the_content', '\\VIP_CacheTheContent_Plugin\\Cache\\load', 1, 1 );
}

/**
 * Filters block pre-rendering during caching. If a block is bypassed,
 * return its serialized source so it stays dynamic at view time.
 *
 * @param string|null  $pre_render Pre-render value (null to continue normal rendering).
 * @param array        $block      Parsed block array.
 * @return string|null Serialized source or null to continue.
 */
function pre_render_block_filter( $pre_render, $block ) {
    $name = $block['blockName'] ?? null;

    if ( $name && has_block_bypass( $name ) ) {
        // Return raw block source (with <!-- wp:... -->) so it stays in the cache,
        // and will be rendered on each request.
        return \serialize_block( $block );
    }

    return $pre_render;
}

/**
 * Optimizes front-end block asset loading on cache hits.
 *
 * When a singular post has a valid cached entry, this function disables
 * WordPress’s default block-asset presence scanning
 * (`wp_enqueue_registered_block_scripts_and_styles`) to avoid repeated
 * `parse_blocks()` work on every request. Instead, it enqueues styles and
 * scripts based solely on the block list stored during cache generation.
 *
 * Behavior:
 * - On cache HIT:
 *     • Removes the core presence-based enqueue callback.
 *     • Enqueues only the previously recorded block assets.
 *
 * - On cache MISS:
 *     • Core block asset detection runs normally.
 *
 * Notes:
 * - Bypassed blocks are unaffected; they continue to render live and must
 *   self-enqueue any assets they require.
 * - This optimization reduces CPU overhead on high-traffic sites by
 *   eliminating redundant block parsing during `enqueue_block_assets`.
 *
 * Hook:
 * - Runs early on `enqueue_block_assets` so that default callbacks can be
 *   cleanly removed before they execute.
 *
 * @return void
 */
function maybe_optimize_block_asset_enqueues() {
    if ( ! \is_singular( get_posttypes() ) ) return;

    global $post;
    if ( ! ( $post instanceof \WP_Post ) ) return;

    $cached = \VIP_CacheTheContent_Plugin\Cache\get( $post->ID );
    if ( empty( $cached['content'] ) || empty( $cached['enqueues'] ) ) {
        // No cache hit – let core do its normal presence-based scan
        return;
    }

    // 1) Disable core's auto-enqueue of block.json assets for this request
    \remove_action(
        'enqueue_block_assets',
        'wp_enqueue_registered_block_scripts_and_styles'
    );

    // 2) Enqueue assets based on our stored block list
    \VIP_CacheTheContent_Plugin\Tools\enqueue_block_assets( $cached['enqueues'] );
}

    /**
 * Determines whether a block should be bypassed from caching.
 * Uses `vip_cachethecontent_bypass` filter.
 *
 * @param string $block_name
 * @return bool
 */
function has_block_bypass( $block_name ) {
    $bypass = \apply_filters( 'vip_cachethecontent_bypass', false, $block_name );
    return $bypass;
}

/**
 * Returns a list of post types eligible for caching.
 * Uses `vip_cachethecontent_posttypes` filter.
 *
 * @return array
 */
function get_posttypes() {
    $posttypes = \apply_filters( 'vip_cachethecontent_posttypes', [ 'post', 'page' ] );
    return $posttypes;
}
