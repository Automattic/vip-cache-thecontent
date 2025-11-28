# [VIP] TheContent Cache

Caches `the_content` for `post` and `page` post-types by processing Gutenberg blocks and storing their rendered output. Associated block CSS and JS dependencies are recorded and reloaded with each page view. This reduces rendering overhead by avoiding repeated processing of expensive inner blocks, while preserving dynamic block behavior where needed.

Additional filters are available to include custom post-types in the caching logic and to exclude specific blocks from being cached, allowing them to be rendered dynamically on each request.

# Extending Functionality

## Block Bypass Filter

Certain Gutenberg blocks can be excluded from cached rendering using the `vip_thecontentcache_bypass` filter. The filter receives two parameters: a `$flag` and the `$block_name`. To bypass caching for a specific block, return `true` when the block name matches your condition.

:warning: Any bypassed block must self-enqueue all frontend assets and side effects at render time.

### Example:

```
add_filter( 'vip_thecontentcache_bypass', 
    'vip_cache_thecontent_block_bypass', 10, 2 );
function vip_cache_thecontent_block_bypass( $flag, $block_name ) {
    if ( 'myblock/name' === $block_name ) return true;
    return $flag;
}
```

## Additional Post-Types Filter

Custom post-types are not included in caching by default. To modify which post types are cached, use the `vip_thecontentcache_posttypes` filter. This filter receives an array of post type names, defaulting to `[ 'post', 'page' ]`. You can extend caching support by adding your custom post type to the array, or remove post or page if they should be excluded from caching.

### Example:

```
add_filter( 'vip_thecontentcache_posttypes', 
    'vip_cache_thecontent_add_posttype', 10, 1 );
function vip_cache_thecontent_add_posttype( array $list ) {
    return array_merge( $list, [ 
        'my-custom-posttype',
    ] );
}
```

# Background Cache Regeneration (Optional)

A settings checkbox is available under:

Settings → Reading → “[VIP] TheContent Cache”

When enabled, the plugin will refresh cached Gutenberg block output in the background after post updates. This avoids forcing frontend users to trigger regeneration on the next page view.

## :warning: Requirement: Action Scheduler

Background regeneration requires the Action Scheduler library to be present:
https://actionscheduler.org/

If Action Scheduler is not available:
1. The checkbox will not be shown.
2. The option will be automatically disabled.
3. Cached entries will be cleared immediately on post save, falling back to standard regeneration on the next frontend request.

This ensures consistent behavior regardless of environment support.