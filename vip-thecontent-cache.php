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
namespace VIP_PostContent_Cache {

    const CACHE_GROUP = 'VIP';

    add_action( 'init', function() {
        // Front-End ( Non-Admin ) actions
        if ( \is_admin() || \wp_doing_ajax() || \wp_is_json_request() ) return;

        \VIP_PostContent_Cache\Hooks\register();
    });

}

// Hooks
namespace VIP_PostContent_Cache\Hooks {

    /**
     * Registers front-end hooks for caching and loading post content during template rendering.
     */
    function register() {
        \add_action( 'template_redirect',
            __NAMESPACE__ . '\\ensure_post_content_cached', 991 );

        \add_action( 'template_redirect',
            __NAMESPACE__ . '\\ensure_post_content_loaded', 992 );

        // Restore cached Style Engine CSS early (before wp_head finishes)
        \add_action( 'wp_enqueue_scripts',
            __NAMESPACE__ . '\\restore_cached_style_engine_css', 100 );

        \add_action( 'enqueue_block_assets',
            __NAMESPACE__ . '\\maybe_optimize_block_asset_enqueues', 1);
    }

    /**
     * Restores cached Style Engine CSS on cache hit.
     * Runs on wp_enqueue_scripts to ensure CSS appears in <head>.
     */
    function restore_cached_style_engine_css() {
        if ( ! \is_singular( \VIP_PostContent_Cache\Allow\posttypes() ) ) return;

        global $post;
        if ( ! ( $post instanceof \WP_Post ) ) return;

        // Only restore if loading from object cache (not just generated this request)
        $mem =& \VIP_PostContent_Cache\Cache\_local_store();
        if ( isset( $mem[ $post->ID ] ) ) return; // Generated this request, Style Engine handles it

        $cached = \VIP_PostContent_Cache\Cache\get( $post->ID );
        if ( empty( $cached['style_engine_css'] ) ) return;

        // Output Style Engine CSS via wp_head
        $css = $cached['style_engine_css'];
        \add_action( 'wp_head', function() use ( $css ) {
            echo '<style id="vip-cached-block-supports">' . 
                wp_strip_all_tags ( $css ) . 
                '</style>' . 
                PHP_EOL;
        }, 999 );
    }

    /**
     * Ensures the current post's content is cached, if it matches allowed post types and is not already cached.
     */
    function ensure_post_content_cached() {
        if ( ! \is_singular( \VIP_PostContent_Cache\Allow\posttypes() ) ) return;

        global $post;
		if ( ! ( $post instanceof \WP_Post ) ) return;
        if ( ! \has_blocks( $post ) ) return;

        $cached = \VIP_PostContent_Cache\Cache\get( $post->ID );
        if ( empty( $cached ) ) \VIP_PostContent_Cache\Cache\set( $post->ID );
    }

    /**
     * Attaches the content-loading filter to `the_content` to replace it with cached output and enqueue assets.
     */
    function ensure_post_content_loaded() {
        if ( ! \is_singular( \VIP_PostContent_Cache\Allow\posttypes() ) ) return;

        \add_filter( 'the_content', '\VIP_PostContent_Cache\Cache\load', 1, 1 );
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

        if ( $name && \VIP_PostContent_Cache\Allow\bypass( $name ) ) {
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
        if ( ! \is_singular( \VIP_PostContent_Cache\Allow\posttypes() ) ) return;

        global $post;
        if ( ! ( $post instanceof \WP_Post ) ) return;

        $cached = \VIP_PostContent_Cache\Cache\get( $post->ID );
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
        \VIP_PostContent_Cache\Misc\enqueue_block_assets( $cached['enqueues'] );
    }

}

// Caching functions
namespace VIP_PostContent_Cache\Cache {

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
        static $mem = []; return $mem;
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
        $block_names = \VIP_PostContent_Cache\Misc\collect_block_names( $blocks );

        // If there are no cacheable blocks, ensure any old cache is removed
        if ( empty( $block_names ) ) {
            delete( $post_id );
            return;
        }

        // Filter the necessary blocks and prepare content for caching
        \add_filter( 'pre_render_block', '\VIP_PostContent_Cache\Hooks\pre_render_block_filter', 10, 2 );

        // Process content and blocks
        $filtered_content = \apply_filters( 'the_content', $the_post->post_content );

        // disconnect filters
        \remove_filter( 'pre_render_block', '\VIP_PostContent_Cache\Hooks\pre_render_block_filter', 10, 2 );

        // Capture Style Engine CSS (wp-elements-* classes, block supports)
        $style_engine_css = \VIP_PostContent_Cache\Misc\capture_style_engine_css();

        // Cache block list and content, if applicable
        if ( ! empty( $block_names ) ) {

            // Filter out blocks that might not have either scripts or styles
            foreach( $block_names as $index => $tmp_block_name ) {
                $tmp = (array) \VIP_PostContent_Cache\Misc\get_block_assets( $tmp_block_name );
                if ( empty( $tmp['style'] ) && empty( $tmp['script'] ) && empty( $tmp['module'] ) ) unset( $block_names[ $index ] );
            }

            $_cached_result = [
                'content' => $filtered_content,
                'enqueues' => $block_names,
                'style_engine_css' => $style_engine_css,
            ];

            // Set Local Cache
            $mem =& _local_store();
            $mem[ $post_id ] = $_cached_result;

            // @TODO: Check if the store is too big -> error instead of caching

            // Set Object Cache
            $_cached_key = \VIP_PostContent_Cache\Cache\key( $post_id );
            \wp_cache_set( $_cached_key,
                $_cached_result,
                \VIP_PostContent_Cache\CACHE_GROUP,
                HOUR_IN_SECONDS );
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
        $_cached_key    = \VIP_PostContent_Cache\Cache\key( $post_id );

        // Object Cache
        $_cached_result = \wp_cache_get( $_cached_key, \VIP_PostContent_Cache\CACHE_GROUP );

        if ( false === $_cached_result ) return null;
        else return $_cached_result;
    }

    /**
     * Replaces `the_content` with cached output if available, and enqueues recorded block assets.
     *
     * @param string $content Original content.
     * @return string Cached or original content.
     */
    function load( string $content ): string {
        if ( \is_admin() || \wp_doing_ajax() || \wp_is_json_request()
			|| !\is_singular( \VIP_PostContent_Cache\Allow\posttypes() ) ) {
            return $content;
        }

        global $post;
        if ( ! ( $post instanceof \WP_Post ) ) return $content;

        $_cached_result = \VIP_PostContent_Cache\Cache\get( $post->ID );

        if ( ! empty( $_cached_result['enqueues'] ) && ! empty( $_cached_result['content'] ) ) {
            \VIP_PostContent_Cache\Misc\enqueue_block_assets( $_cached_result['enqueues'] );
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
        return '_vip_thecontent_' . $post_id;
    }

    function delete( int $post_id ): void {
        // Just delete the transients...
		$_cached_key = key( $post_id );
		\wp_cache_delete( $_cached_key, \VIP_PostContent_Cache\CACHE_GROUP );

        // but also clear the local memory just in case
        $mem =& _local_store();
        unset( $mem[ $post_id ] );
    }

}

// Assistant functions
namespace VIP_PostContent_Cache\Misc {

    /**
     * Recursively collects block names from a block tree, excluding bypassed blocks.
     *
     * @param array $blocks Parsed block array.
     * @return array Unique list of block names.
     */
    function collect_block_names( array $blocks ) {
        $names = [];

        foreach ( $blocks as $block ) {
            if ( ! empty( $block['blockName'] ) &&
            !\VIP_PostContent_Cache\Allow\bypass( $block['blockName'] ) ) {
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

    /**
     * Collects registered front-end asset handles for a block.
     *
     * Reads the block’s registration (via WP_Block_Type_Registry) and returns a
     * normalized list of handles for **styles**, **front-end scripts**, and **script modules**:
     * - Styles come from `$block_type->style` (string|array).
     * - Scripts prefer `$block_type->view_script` (front-end only), then legacy
     *   `$block_type->script` (editor+front-end in older blocks).
     * - Modules come from `$block_type->view_script_module_ids` (WP 6.5+, Interactivity API).
     *
     * Editor-only assets (e.g., editor_style/editor_script) are intentionally ignored.
     *
     * @param string $block_name Block name (e.g., ‘core/paragraph’).
     * @return array{style:string[], script:string[], module:string[]}|null Array of handles, or null if the block is not registered.
     *
     * @note Handles may be registered as a string or an array; this function
     *       normalizes them to arrays and filters out empty values.
     * @example
     *   // [‘style’ => [‘core-blocks’], ‘script’ => [‘my-frontend-js’], ‘module’ => [‘@wordpress/interactivity’]]
     */
    function get_block_assets( $block_name ) {
        $registry = \WP_Block_Type_Registry::get_instance();
        $block_type = $registry->get_registered( $block_name );

        if ( ! $block_type ) return null;

        $styles_scripts_list = [ ‘style’ => [], ‘script’ => [], ‘module’ => [] ];

        // style can be string or array
        $styles = $block_type->style ?? [];
        foreach ( (array) $styles as $h ) {
            if ( is_string( $h ) && $h !== ‘’ ) $styles_scripts_list[‘style’][] = $h;
        }

        // prefer view_script for frontend behavior
        $view_scripts = $block_type->view_script ?? [];
        foreach ( (array) $view_scripts as $h ) {
            if ( is_string( $h ) && $h !== ‘’ ) $styles_scripts_list[‘script’][] = $h;
        }

        // keep legacy ‘script’ for blocks that still use it
        $scripts = $block_type->script ?? [];
        foreach ( (array) $scripts as $h ) {
            if ( is_string( $h ) && $h !== ‘’ ) $styles_scripts_list[‘script’][] = $h;
        }

        // WP 6.5+: Interactivity API module IDs (viewScriptModule in block.json)
        $modules = $block_type->view_script_module_ids ?? [];
        foreach ( (array) $modules as $h ) {
            if ( is_string( $h ) && $h !== ‘’ ) $styles_scripts_list[‘module’][] = $h;
        }

        return $styles_scripts_list;
    }

    /**
     * Enqueues front-end styles, scripts, and script modules for the given blocks (bulk, de-duplicated).
     *
     * For each block name, this gathers handles via get_block_assets() and enqueues
     * them through the global registries:
     * - Styles are enqueued with `wp_styles()->enqueue( $handles )`.
     * - Scripts are enqueued with `wp_scripts()->enqueue( $handles )`.
     * - Modules (WP 6.5+) are enqueued with `wp_enqueue_script_module( $id )`.
     *
     * Dependency resolution is handled by WordPress, as with individual enqueue calls.
     * Editor-only assets are not enqueued here.
     *
     * @param string[] $blocks_list List of block names present in the rendered content.
     * @return void
     *
     * @best-practice Call before `wp_head` runs so styles land in `<head>`; keeping
     *               a late call as a safety net is acceptable for scripts.
     * @example
     *   enqueue_block_assets( ['core/paragraph', 'my/plugin-block'] );
     */
    function enqueue_block_assets( $blocks_list ) {
        if (empty( $blocks_list ) ) return;
        $enqueue_styles = $enqueue_scripts = $enqueue_modules = [];

        foreach ( $blocks_list as $block_name ) {
            $tmp_block_enqueues = get_block_assets( $block_name );

            if ( ! empty( $tmp_block_enqueues['script'] ) ) {
                $enqueue_scripts = array_merge(
                    (array) $enqueue_scripts,
                    (array) $tmp_block_enqueues['script']
                );
            }
            if ( ! empty( $tmp_block_enqueues['style'] ) ) {
                $enqueue_styles = array_merge(
                    (array) $enqueue_styles,
                    (array) $tmp_block_enqueues['style']
                );
            }
            if ( ! empty( $tmp_block_enqueues['module'] ) ) {
                $enqueue_modules = array_merge(
                    (array) $enqueue_modules,
                    (array) $tmp_block_enqueues['module']
                );
            }
        }

        // Handle style enqueues
        if ( ! empty( $enqueue_styles ) ) {
            $enqueue_styles = \array_unique( $enqueue_styles );
            \wp_styles()->enqueue( $enqueue_styles );
        }

        // Handle script enqueues
        if ( ! empty( $enqueue_scripts ) ) {
            $enqueue_scripts = \array_unique( $enqueue_scripts );
            \wp_scripts()->enqueue( $enqueue_scripts );
        }

        // Handle module enqueues (WP 6.5+ Interactivity API)
        if ( ! empty( $enqueue_modules ) && \function_exists( 'wp_enqueue_script_module' ) ) {
            foreach ( \array_unique( $enqueue_modules ) as $module_id ) {
                \wp_enqueue_script_module( $module_id );
            }
        }
    }

    /**
     * Captures CSS from WordPress Style Engine stores.
     *
     * The Style Engine (WP 6.1+) stores CSS for block supports like element colors
     * (wp-elements-* classes) separately from wp_styles. This function extracts
     * that CSS after block rendering so it can be cached and restored.
     *
     * @return string Compiled CSS from all Style Engine stores.
     */
    function capture_style_engine_css(): string {
        if ( ! class_exists( 'WP_Style_Engine_CSS_Rules_Store' ) ) {
            return '';
        }

        $all_css = '';
        $stores = \WP_Style_Engine_CSS_Rules_Store::get_stores();

        foreach ( array_keys( $stores ) as $store_name ) {
            $css = \wp_style_engine_get_stylesheet_from_context( $store_name );
            if ( ! empty( $css ) ) {
                $all_css .= $css . "\n";
            }
        }

        return $all_css;
    }

}

// Allow list
namespace VIP_PostContent_Cache\Allow {

    /**
     * Determines whether a block should be bypassed from caching.
     * Uses `vip_thecontentcache_bypass` filter.
     *
     * @param string $block_name
     * @return bool
     */
    function bypass( $block_name ) {
		return \apply_filters( 'vip_thecontentcache_bypass', false, $block_name );
    }

    /**
     * Returns a list of post types eligible for caching.
     * Uses `vip_thecontentcache_posttypes` filter.
     *
     * @return array
     */
	function posttypes() {
		return \apply_filters( 'vip_thecontentcache_posttypes', [ 'post', 'page' ] );
	}

    /**
     * Determines whether Action Scheduler is available for use.
     *
     * Checks for the existence of the required Action Scheduler functions:
     * - as_schedule_single_action()
     * - as_next_scheduled_action()
     * - as_unschedule_all_actions()
     *
     * Returns true only if all required functions exist.
     *
     * @return bool Whether Action Scheduler is available.
     */
    function is_as() {
        return (
            \function_exists( 'as_schedule_single_action' ) &&
            \function_exists( 'as_next_scheduled_action' ) &&
            \function_exists( 'as_unschedule_all_actions' )
        );
    }

}

// Admin functions
namespace VIP_PostContent_Cache\Admin {

    const CACHE_REFRESH_ENABLE_OPTION = 'vip_thecontentcache_cron_regenerate';
    const CACHE_REFRESH_CRON_NAME     = 'vip_thecontentcache_schedule_set';

    \add_action( 'admin_init', '\VIP_PostContent_Cache\Admin\ui_register_enable_option' );
    \add_action( 'admin_init', '\VIP_PostContent_Cache\Admin\register' );

    /**
     * Registers admin-side hooks for cache management and background regeneration.
     *
     * Hooks added:
     * - `save_post` → triggers post cache scheduling or deletion logic.
     * - `wp_trash_post` → deletes cache for trashed posts.
     * - Action Scheduler hook (CACHE_REFRESH_CRON_NAME) → calls Cache\set() when scheduled.
     *
     * This function runs on `admin_init` and prepares the admin environment
     * for both manual and automated cache invalidation.
     *
     * @return void
     */
    function register() {
		\add_action( 'save_post',
			'\VIP_PostContent_Cache\Admin\on_save_post', 10, 2 );
		\add_action( 'wp_trash_post',
			'\VIP_PostContent_Cache\Admin\on_trash_post', 10, 1 );
        \add_action( \VIP_PostContent_Cache\Admin\CACHE_REFRESH_CRON_NAME,
            '\VIP_PostContent_Cache\Cache\set', 10 );
    }

    /**
     * Registers the admin UI checkbox that enables or disables recurring cache regeneration.
     *
     * This function runs on `admin_init` and:
     * - Hides the option entirely if Action Scheduler is not available.
     * - Registers a checkbox under Settings → Reading.
     * - Registers the associated option for sanitization and persistence.
     *
     * The checkbox controls whether post-content cache updates are performed
     * through Action Scheduler or fall back to immediate deletion on save.
     *
     * @return void
     */
    function ui_register_enable_option() {

        // If there is no AS, there is no need to have this option
        if ( ! \VIP_PostContent_Cache\Allow\is_as() ) {
            delete_option( \VIP_PostContent_Cache\Admin\CACHE_REFRESH_ENABLE_OPTION );
            return;
        }

        // Add Settings field
        \add_settings_field(
            \VIP_PostContent_Cache\Admin\CACHE_REFRESH_ENABLE_OPTION,
            __( '[VIP] TheContent Cache', 'vip-thecontent-cache' ),
            function() {
                $enabled = (bool) \get_option( \VIP_PostContent_Cache\Admin\CACHE_REFRESH_ENABLE_OPTION, false );
                ?>
                <label>
                    <input type="checkbox" name="<?php echo \esc_attr( \VIP_PostContent_Cache\Admin\CACHE_REFRESH_ENABLE_OPTION ); ?>" value="1" <?php \checked( $enabled ); ?>>
                    <?php \esc_html_e( 'Update cached Gutenberg blocks on post-type update.', 'vip-thecontent-cache' ); ?>
                </label>
                <?php
            },
            'reading'
        );
        // and processing for the Settings field
        \register_setting( 'reading', \VIP_PostContent_Cache\Admin\CACHE_REFRESH_ENABLE_OPTION, [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => false,
        ] );
    }

    /**
     * Deletes cached content for a post when it is moved to the trash.
     *
     * This removes both cached HTML and cached block asset lists
     * to prevent stale content from being served.
     *
     * @param int $post_id ID of the post being trashed.
     * @return void
     */
	function on_trash_post( $post_id ) {
		\VIP_PostContent_Cache\Cache\delete( $post_id );
	}

    /**
     * Handles cache invalidation or scheduled regeneration when a post is saved.
     *
     * Behavior:
     * - Ignores autosaves and revisions.
     * - Only operates on post types allowed by the plugin's allowlist.
     * - If Action Scheduler is unavailable, cache is deleted immediately.
     * - If enabled via settings, schedules a background cache rebuild via
     *   Action Scheduler (idempotent: skips scheduling if already queued).
     * - If the option is disabled, any pending AS actions for this post are
     *   cancelled and the cache is deleted immediately.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post     Post object.
     * @return void
     */
	function on_save_post( $post_id, $post ) {
		if ( ! in_array( $post->post_type, \VIP_PostContent_Cache\Allow\posttypes(), true ) ) return;
		if ( \wp_is_post_autosave( $post_id ) || \wp_is_post_revision( $post_id ) ) return;

        $option_enabled = (bool) \get_option( \VIP_PostContent_Cache\Admin\CACHE_REFRESH_ENABLE_OPTION, false );

        if ( ! \VIP_PostContent_Cache\Allow\is_as() ) {
            // No Action Scheduler: clear cache now; front-end regenerates on next view.
            \VIP_PostContent_Cache\Cache\delete( $post_id );
        } elseif ( $option_enabled ) {
            // Schedule a background rebuild via Action Scheduler if not already queued.
            if ( ! \as_next_scheduled_action( \VIP_PostContent_Cache\Admin\CACHE_REFRESH_CRON_NAME, [ $post_id ] ) ) {
                \as_schedule_single_action(
                    time() + 10,
                    \VIP_PostContent_Cache\Admin\CACHE_REFRESH_CRON_NAME,
                    [ $post_id ]
                );
            }
        } else {
            // Option disabled: cancel any pending AS actions and clear the cache now.
            \as_unschedule_all_actions(
                \VIP_PostContent_Cache\Admin\CACHE_REFRESH_CRON_NAME,
                [ $post_id ]
            );
            \VIP_PostContent_Cache\Cache\delete( $post_id );
        }
	}

}
