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
 * Requires PHP:      8.3
 */

// Main
namespace VIP_PostContent_Cache {

    const CACHE_GROUP = 'VIP';

    add_action( 'init', function() {
        // Front-End ( Non-Admin ) actions
        if ( ! \is_admin() && ! \wp_doing_ajax() && ! \wp_is_json_request() ) {
            \VIP_PostContent_Cache\Hooks\register();
        }
        // Admin actions
        else {
            \VIP_PostContent_Cache\Admin\register();
        }
    });

}

// Hooks
namespace VIP_PostContent_Cache\Hooks {

    function register() {
        \add_action( 'template_redirect', 
            __NAMESPACE__ . '\\ensure_post_content_cached', 991 );

        \add_action( 'template_redirect', 
            __NAMESPACE__ . '\\ensure_post_content_loaded', 992 );
    }

    function ensure_post_content_cached() {
        if ( ! is_singular( \VIP_PostContent_Cache\Allow\posttypes() ) ) return;

        global $post;
		if ( ! ( $post instanceof \WP_Post ) ) return;
    
        $cached = \VIP_PostContent_Cache\Cache\get( $post->ID );
        if ( ! $cached ) \VIP_PostContent_Cache\Cache\set( $post->ID );
    }

    function ensure_post_content_loaded() {
        if ( ! is_singular( \VIP_PostContent_Cache\Allow\posttypes() ) ) return;

        add_filter( 'the_content', '\VIP_PostContent_Cache\Cache\load', 1, 1 );
    }

    function render_block_filter( $block_content, $block ) {
        if ( \VIP_PostContent_Cache\Allow\blocks( $block['blockName'] ) ) {
            return serialize_block( $block );
        }
        return $block_content;
    }

}

// Caching functions
namespace VIP_PostContent_Cache\Cache {

    function set( $post_id ) {
        $post = \get_post( $post_id );
        if ( ! ( $post instanceof \WP_Post ) ) return;

        $blocks      = \parse_blocks( $post->post_content );
        $block_names = \VIP_PostContent_Cache\Misc\collect_block_names( $blocks );

        \add_filter( 'render_block', '\VIP_PostContent_Cache\Hooks\render_block_filter', 10, 2 );
        $content     = \apply_filters( 'the_content', $post->post_content );
        \remove_filter( 'render_block', '\VIP_PostContent_Cache\Hooks\render_block_filter', 10, 2 );

        if ( ! empty( $block_names ) ) {
            $_cached_key = key( $post_id );

            \wp_cache_set( $_cached_key . '_enqueues',
                maybe_serialize( $block_names ), 
                \VIP_PostContent_Cache\CACHE_GROUP, 
                HOUR_IN_SECONDS );
            \wp_cache_set( $_cached_key . '_content',
                $content, 
                \VIP_PostContent_Cache\CACHE_GROUP, 
                HOUR_IN_SECONDS );
        }
    }

    function get( $post_id ) {
        $_cached_key    = key( $post_id );
        $_cached_result = \wp_cache_get( $_cached_key . '_content', \VIP_PostContent_Cache\CACHE_GROUP );
        if ( false === $_cached_result ) return null;

        $_enqueues  = \maybe_unserialize( \wp_cache_get( $_cached_key . '_enqueues', \VIP_PostContent_Cache\CACHE_GROUP ) );
        if ( false === $_enqueues ) return null;

        return [
            'content' => $_cached_result,
            'enqueues' => $_enqueues,
        ];
    }

    function load( $content ) {
        if ( \is_admin() || \wp_doing_ajax() || \wp_is_json_request() 
			|| !\is_singular( \VIP_PostContent_Cache\Allow\posttypes() ) ) {
            return $content;
        }

        global $post;
        if ( ! ( $post instanceof \WP_Post ) ) return;

        $_cached_result = \VIP_PostContent_Cache\Cache\get( $post->ID );

        if ( ! empty( $_cached_result['enqueues'] ) && ! empty( $_cached_result['content'] ) ) {
            foreach ( $_cached_result['enqueues'] as $block_name ) {
                \VIP_PostContent_Cache\Misc\enqueue_block_assets( $block_name );
            }
            return $_cached_result['content'];
        }

        return $content; // Fallback to original content if cache is missing
    }

    function key( $post_id ) {
        return '_vip_thecontent_' . $post_id;
    }

}

// Assistant functions
namespace VIP_PostContent_Cache\Misc {

    function collect_block_names( array $blocks ) {
        $names = [];

        foreach ( $blocks as $block ) {
            if ( ! empty( $block['blockName'] ) && 
            !\VIP_PostContent_Cache\Allow\blocks( $block['blockName'] ) ) {
                $names[] = $block['blockName'];
            }
            if ( ! empty( $block['innerBlocks'] ) ) {
                $inner_names = collect_block_names( $block['innerBlocks'] );
                if ( ! empty( $inner_names ) ) $names = array_merge( $names, $inner_names );
            }
        }

        $names = \array_unique( $names );
        return $names;
    }

    function enqueue_block_assets( $block_name ) {
        $registry = \WP_Block_Type_Registry::get_instance();
        $block_type = $registry->get_registered( $block_name );

        if ( $block_type ) {
            if ( !empty( $block_type->style ) )  \wp_enqueue_style( $block_type->style );
            if ( !empty( $block_type->script ) ) \wp_enqueue_script( $block_type->script );
        }
    }

}

// Allow list
namespace VIP_PostContent_Cache\Allow {

    function blocks( $block_name ) {
		return apply_filters( 'vip_postcontent_cache_is_bypassed', false, $block_name );
    }

	function posttypes() {
		return apply_filters( 'vip_postcontent_cache_is_posttype', [ 'post', 'page' ] );
	}

}

// Admin functions
namespace VIP_PostContent_Cache\Admin {

    function register() {
		\add_action( 'save_post', 
			'\VIP_PostContent_Cache\Admin\on_save_post', 10, 2 );
		\add_action( 'wp_trash_post', 
			'\VIP_PostContent_Cache\Admin\on_trash_post', 10, 1 );
    }

	function on_trash_post( $post_id ) {
		// Just delete the transients...
		$_cached_key = \VIP_PostContent_Cache\Cache\key( $post_id );
		\wp_cache_delete( $_cached_key . '_enqueues',
			\VIP_PostContent_Cache\CACHE_GROUP );
		\wp_cache_delete( $_cached_key . '_content',
			\VIP_PostContent_Cache\CACHE_GROUP );
	}

	function on_save_post( $post_id, $post ) {
		if ( ! in_array( $post->post_type, \VIP_PostContent_Cache\Allow\posttypes(), true ) ) return;
		if ( \wp_is_post_autosave( $post_id ) || \wp_is_post_revision( $post_id ) ) return;

		wp_schedule_single_event( time() + 30, '\VIP_PostContent_Cache\Cache\set(', [
			'post_id' => $post_id,
		] );
	}

}