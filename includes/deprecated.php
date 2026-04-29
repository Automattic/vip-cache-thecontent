<?php
/**
 * Backward-compatible wrappers for previous namespaces and function names.
 *
 * @package VIP_PostContent_Cache
 */

namespace VIP_PostContent_Cache\Allow {

    /**
     * @deprecated Use \VIP_PostContent_Cache\Tools\has_block_bypass().
     *
     * @param string $block_name Block name.
     * @return bool
     */
    function bypass( $block_name ) {
        return \VIP_PostContent_Cache\Tools\has_block_bypass( $block_name );
    }

    /**
     * @deprecated Use \VIP_PostContent_Cache\Tools\get_posttypes().
     *
     * @return array
     */
	function posttypes() {
        return \VIP_PostContent_Cache\Tools\get_posttypes();
	}

    /**
     * @deprecated Use \VIP_PostContent_Cache\Tools\has_action_scheduler().
     *
     * @return bool
     */
    function is_as() {
        return \VIP_PostContent_Cache\Tools\has_action_scheduler();
    }

}

namespace VIP_PostContent_Cache\Misc {

    /**
     * @deprecated Use \VIP_PostContent_Cache\Tools\collect_block_names().
     *
     * @param array $blocks Parsed block array.
     * @return array
     */
    function collect_block_names( array $blocks ) {
        return \VIP_PostContent_Cache\Tools\collect_block_names( $blocks );
    }

    /**
     * @deprecated Use \VIP_PostContent_Cache\Tools\get_block_assets().
     *
     * @param string $block_name Block name.
     * @return array|null
     */
    function get_block_assets( $block_name ) {
        return \VIP_PostContent_Cache\Tools\get_block_assets( $block_name );
    }

    /**
     * @deprecated Use \VIP_PostContent_Cache\Tools\enqueue_block_assets().
     *
     * @param string[] $blocks_list List of block names.
     * @return void
     */
    function enqueue_block_assets( $blocks_list ) {
        \VIP_PostContent_Cache\Tools\enqueue_block_assets( $blocks_list );
    }

    /**
     * @deprecated Use \VIP_PostContent_Cache\Tools\capture_style_engine_css().
     *
     * @return string
     */
    function capture_style_engine_css(): string {
        return \VIP_PostContent_Cache\Tools\capture_style_engine_css();
    }

}

namespace VIP_PostContent_Cache\Hooks {

    /**
     * @deprecated Use \VIP_PostContent_Cache\Hooks\maybe_cache_post_content().
     *
     * @return void
     */
    function ensure_post_content_cached() {
        \VIP_PostContent_Cache\Hooks\maybe_cache_post_content();
    }

    /**
     * @deprecated Use \VIP_PostContent_Cache\Hooks\register_content_loader().
     *
     * @return void
     */
    function ensure_post_content_loaded() {
        \VIP_PostContent_Cache\Hooks\register_content_loader();
    }

}
