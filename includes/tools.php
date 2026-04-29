<?php
/**
 * Shared helper and capability-check functions.
 *
 * @package VIP_CacheTheContent_Plugin
 */

namespace VIP_CacheTheContent_Plugin\Tools {

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
            !\VIP_CacheTheContent_Plugin\Hooks\has_block_bypass( $block['blockName'] ) ) {
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
     * normalized list of handles for **styles** and **front-end scripts**:
     * - Styles come from `$block_type->style` (string|array).
     * - Scripts prefer `$block_type->view_script` (front-end only), then legacy
     *   `$block_type->script` (editor+front-end in older blocks).
     *
     * Editor-only assets (e.g., editor_style/editor_script) are intentionally ignored.
     *
     * @param string $block_name Block name (e.g., 'core/paragraph').
     * @return array{style:string[], script:string[]}|null Array of handles, or null if the block is not registered.
     *
     * @note Handles may be registered as a string or an array; this function
     *       normalizes them to arrays and filters out empty values.
     * @example
     *   // ['style' => ['core-blocks'], 'script' => ['my-frontend-js']]
     */
    function get_block_assets( $block_name ) {
        $registry = \WP_Block_Type_Registry::get_instance();
        $block_type = $registry->get_registered( $block_name );

        if ( ! $block_type ) return null;

        $styles_scripts_list = [ 'style' => [], 'script' => [], ];

        // style can be string or array
        $styles = $block_type->style ?? [];
        foreach ( (array) $styles as $h ) {
            if ( is_string( $h ) && $h !== '' ) $styles_scripts_list['style'][] = $h;
        }

        // prefer view_script for frontend behavior
        $view_scripts = $block_type->view_script ?? [];
        foreach ( (array) $view_scripts as $h ) {
            if ( is_string( $h ) && $h !== '' ) $styles_scripts_list['script'][] = $h;
        }

        // keep legacy 'script' for blocks that still use it
        $scripts = $block_type->script ?? [];
        foreach ( (array) $scripts as $h ) {
            if ( is_string( $h ) && $h !== '' ) $styles_scripts_list['script'][] = $h;
        }

        return $styles_scripts_list;
    }

    /**
     * Enqueues front-end styles and scripts for the given blocks (bulk, de-duplicated).
     *
     * For each block name, this gathers handles via get_block_assets() and enqueues
     * them through the global registries:
     * - Styles are enqueued with `wp_styles()->enqueue( $handles )`.
     * - Scripts are enqueued with `wp_scripts()->enqueue( $handles )`.
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
        $enqueue_styles = $enqueue_scripts = [];

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

    /**
     * Determines whether this request should not run content-cache behavior.
     *
     * @return bool
     */
    function is_not_content_request() {
        return \is_admin() || \wp_doing_ajax() || \wp_is_json_request();
    }

    /**
     * Determines whether Action Scheduler is available for use.
     *
     * Checks for the existence of the required core Action Scheduler functions:
     * - as_schedule_recurring_action()
     * - as_next_scheduled_action()
     * - as_unschedule_all_actions()
     *
     * Returns true only if all required functions exist.
     *
     * @return bool Whether Action Scheduler is available.
     */
    function has_action_scheduler() {
        return (
            \function_exists( 'as_schedule_recurring_action' ) &&
            \function_exists( 'as_next_scheduled_action' ) &&
            \function_exists( 'as_unschedule_all_actions' )
        );
    }

}
