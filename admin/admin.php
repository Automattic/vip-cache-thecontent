<?php
/**
 * Admin settings and cache lifecycle hooks.
 *
 * @package VIP_PostContent_Cache
 */

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
        if ( ! \VIP_PostContent_Cache\Tools\has_action_scheduler() ) {
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
     * - If enabled via settings, schedules a background cache rebuild
     *   via Action Scheduler.
     * - If the option is disabled, any pending scheduled rebuilds for this
     *   post are unscheduled.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post     Post object.
     * @return void
     */
	function on_save_post( $post_id, $post ) {
		if ( ! in_array( $post->post_type, \VIP_PostContent_Cache\Tools\get_posttypes(), true ) ) return;
		if ( \wp_is_post_autosave( $post_id ) || \wp_is_post_revision( $post_id ) ) return;

        // --- Handle scheduling or unscheduling ---
        $option_enabled = (bool) \get_option( \VIP_PostContent_Cache\Admin\CACHE_REFRESH_ENABLE_OPTION, false );

        if ( ! \VIP_PostContent_Cache\Tools\has_action_scheduler() ) {
            \VIP_PostContent_Cache\Cache\delete( $post_id );
        } else {
            if ( $option_enabled ) {
                if ( ! \wp_next_scheduled( \VIP_PostContent_Cache\Admin\CACHE_REFRESH_CRON_NAME, [ $post_id ] ) ) {
                    \wp_schedule_single_event( time() + 10,
                        \VIP_PostContent_Cache\Admin\CACHE_REFRESH_CRON_NAME,
                        [ $post_id ]
                    );
                }
            } else {
                // Clear any core-cron events for this post
                \as_unschedule_all_actions(
                    \VIP_PostContent_Cache\Admin\CACHE_REFRESH_CRON_NAME,
                    [ $post_id ]
                );
            }
        }
	}

}
