<?php
/**
 * Content cache helpers.
 *
 * @package VIP_CacheTheContent_Plugin
 */

namespace VIP_CacheTheContent_Plugin\Cache {

    /**
     * Returns a reference to a per-request in-memory store.
     *
     * Used to memoize cached content and enqueue lists within the current request
     * so that repeated calls to set()/get() avoid extra object-cache round-trips.
     * The store is a static array that exists only for the lifetime of the request.
     *
     * Keys: post IDs (int).
     * Values: ['content' => string, 'enqueues' => string[]].
     *
     * Usage:
     *   $mem =& _local_store();
     *   $mem[ $post_id ] = ['content' => $html, 'enqueues' => $blocks];
     *
     * @return array Reference to the per-request memoization array.
     */
    function &_local_store(): array {
        static $mem = [];
        return $mem;
    }

    /**
     * Parses and renders the post content, collects block dependencies, and stores both in object cache.
     *
     * @param int $post_id Post ID.
     */
    function set( int $post_id ): void {
        $the_post = \get_post( $post_id );
        if ( ! ( $the_post instanceof \WP_Post ) ) return;

        // If there are no blocks at all, ensure any old cache is removed
        if ( ! has_blocks( $the_post ) ) {
            delete( $post_id );
            return;
        }

        // Process blocks
        $blocks      = \parse_blocks( $the_post->post_content );
        $block_names = \VIP_CacheTheContent_Plugin\Tools\collect_block_names( $blocks );

        // If there are no cacheable blocks, ensure any old cache is removed
        if ( empty( $block_names ) ) {
            delete( $post_id );
            return;
        }

        // Filter the necessary blocks and prepare content for caching
        \add_filter( 'pre_render_block', '\\VIP_CacheTheContent_Plugin\\Hooks\\pre_render_block_filter', 10, 2 );

        // Process content and blocks
        $filtered_content = \apply_filters( 'the_content', $the_post->post_content );

        // disconnect filters
        \remove_filter( 'pre_render_block', '\\VIP_CacheTheContent_Plugin\\Hooks\\pre_render_block_filter', 10, 2 );

        // Capture Style Engine CSS (wp-elements-* classes, block supports)
        $style_engine_css = \VIP_CacheTheContent_Plugin\Tools\capture_style_engine_css();

        // Cache block list and content, if applicable
        if ( ! empty( $block_names ) ) {

            // Filter out blocks that might not have either scripts or styles
            foreach( $block_names as $index => $tmp_block_name ) {
                $tmp = (array) \VIP_CacheTheContent_Plugin\Tools\get_block_assets( $tmp_block_name );
                if ( empty( $tmp['style'] ) && empty( $tmp['script'] ) ) unset( $block_names[ $index ] );
            }

            // Set Local Cache
            $mem =& _local_store();
            $mem[ $post_id ] = [
                'content' => $filtered_content,
                'enqueues' => $block_names,
                'style_engine_css' => $style_engine_css,
            ];

            // Set Object Cache
            $_cached_key = \VIP_CacheTheContent_Plugin\Cache\key( $post_id );
            \wp_cache_set( $_cached_key . '_enqueues',
                \maybe_serialize( $block_names ),
                \VIP_CacheTheContent_Plugin\CACHE_GROUP,
                \VIP_CacheTheContent_Plugin\CACHE_TTL );
            \wp_cache_set( $_cached_key . '_content',
                $filtered_content,
                \VIP_CacheTheContent_Plugin\CACHE_GROUP,
                \VIP_CacheTheContent_Plugin\CACHE_TTL );
            \wp_cache_set( $_cached_key . '_style_engine_css',
                $style_engine_css,
                \VIP_CacheTheContent_Plugin\CACHE_GROUP,
                \VIP_CacheTheContent_Plugin\CACHE_TTL );
        }
    }

    /**
     * Retrieves cached post content and associated block enqueues.
     *
     * @param int $post_id Post ID.
     * @return array|null Returns array with 'content' and 'enqueues', or null if not cached.
     */
    function get( int $post_id ): ?array {

        // Get from Local Storage, if it was set on this run, without invoking cache
        $mem =& _local_store();
        if ( isset( $mem[ $post_id ] ) ) return $mem[ $post_id ];

        // Get from Object Cache
        $_cached_key    = \VIP_CacheTheContent_Plugin\Cache\key( $post_id );

        // Content Object Cache
        $_cached_result = \wp_cache_get( $_cached_key . '_content', \VIP_CacheTheContent_Plugin\CACHE_GROUP );
        if ( false === $_cached_result ) return null;

        // Enqueue Object Cache
        $_enqueues  = \maybe_unserialize( \wp_cache_get( $_cached_key . '_enqueues', \VIP_CacheTheContent_Plugin\CACHE_GROUP ) );
        if ( false === $_enqueues ) return null;

        // Style Engine CSS (optional - may not exist in older cache entries)
        $_style_engine_css = \wp_cache_get( $_cached_key . '_style_engine_css', \VIP_CacheTheContent_Plugin\CACHE_GROUP );
        $_style_engine_css = ( false !== $_style_engine_css ) ? $_style_engine_css : '';

        return [
            'content' => $_cached_result,
            'enqueues' => $_enqueues,
            'style_engine_css' => $_style_engine_css,
        ];
    }

    /**
     * Replaces `the_content` with cached output if available, and enqueues recorded block assets.
     *
     * @param string $content Original content.
     * @return string Cached or original content.
     */
    function load( string $content ): string {
        if ( \VIP_CacheTheContent_Plugin\Tools\is_not_content_request()
			|| ! \is_singular( \VIP_CacheTheContent_Plugin\Hooks\get_posttypes() ) ) {
            return $content;
        }

        global $post;
        if ( ! ( $post instanceof \WP_Post ) ) return $content;

        $_cached_result = \VIP_CacheTheContent_Plugin\Cache\get( $post->ID );

        if ( ! empty( $_cached_result['enqueues'] ) && ! empty( $_cached_result['content'] ) ) {
            \VIP_CacheTheContent_Plugin\Tools\enqueue_block_assets( $_cached_result['enqueues'] );
            return $_cached_result['content'];
        }

        return $content; // Fallback to original content if cache is missing
    }

    /**
     * Generates the cache key for a given post ID.
     *
     * @param int $post_id
     * @return string
     */
    function key( int $post_id ): string {
        return \VIP_CacheTheContent_Plugin\CACHE_KEY_PREFIX . $post_id;
    }

    function delete( int $post_id ): void {
        // Just delete the transients...
		$_cached_key = key( $post_id );
		\wp_cache_delete( $_cached_key . '_enqueues',
			\VIP_CacheTheContent_Plugin\CACHE_GROUP );
		\wp_cache_delete( $_cached_key . '_content',
			\VIP_CacheTheContent_Plugin\CACHE_GROUP );
		\wp_cache_delete( $_cached_key . '_style_engine_css',
			\VIP_CacheTheContent_Plugin\CACHE_GROUP );

        // but also clear the local memory just in case
        $mem =& _local_store();
        unset( $mem[ $post_id ] );
    }

}
