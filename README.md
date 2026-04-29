# [VIP] Cache TheContent

Caches rendered `the_content` output for `post` and `page` post types by processing Gutenberg blocks and storing their rendered output. Associated block CSS and JS dependencies are recorded and reloaded with each page view. This reduces rendering overhead by avoiding repeated processing of expensive inner blocks, while preserving dynamic block behavior where needed.

Additional filters are available to include custom post types in the caching logic and to exclude specific blocks from being cached, allowing them to be rendered dynamically on each request.

## Extending Functionality

### Block Bypass Filter

Certain Gutenberg blocks can be excluded from cached rendering using the `vip_cachethecontent_bypass` filter. The filter receives two parameters: a `$bypass` flag and the `$block_name`. To bypass caching for a specific block, return `true` when the block name matches your condition.

> Note: Any bypassed block must self-enqueue all frontend assets and side effects at render time.

#### Example

```php
add_filter( 'vip_cachethecontent_bypass', 'vip_cachethecontent_block_bypass', 10, 2 );
function vip_cachethecontent_block_bypass( $bypass, $block_name ) {
    if ( 'myblock/name' === $block_name ) {
        return true;
    }

    return $bypass;
}
```

### Additional Post Types Filter

Custom post types are not included in caching by default. To modify which post types are cached, use the `vip_cachethecontent_posttypes` filter. This filter receives an array of post type names, defaulting to `[ 'post', 'page' ]`. You can extend caching support by adding your custom post type to the array, or remove `post` or `page` if they should be excluded from caching.

#### Example

```php
add_filter( 'vip_cachethecontent_posttypes', 'vip_cachethecontent_add_posttype' );
function vip_cachethecontent_add_posttype( array $posttypes ) {
    $posttypes[] = 'my-custom-posttype';

    return $posttypes;
}
```

## Cache Details

Cached entries are stored in the `VIP` object-cache group. Cache keys use the `_vip_ctc_` prefix.

For post ID `123`, the cached content key is:

```text
_vip_ctc_123_content
```

The default cache TTL is one hour.

## Background Cache Regeneration (Optional)

A settings checkbox is available under:

```text
Settings > Reading > [VIP] Cache TheContent
```

When enabled, the plugin will refresh cached Gutenberg block output in the background after post updates. This avoids forcing frontend users to trigger regeneration on the next page view.

### Requirement: Action Scheduler

Background regeneration requires the Action Scheduler library to be present:

https://actionscheduler.org/

If Action Scheduler is not available:

1. The checkbox will not be shown.
2. The option will be automatically disabled.
3. Cached entries will be cleared immediately on post save, falling back to standard regeneration on the next frontend request.

This ensures consistent behavior regardless of environment support.

## Changes From The Previous README

The public filter names were renamed:

- `vip_thecontentcache_bypass` is now `vip_cachethecontent_bypass`.
- `vip_thecontentcache_posttypes` is now `vip_cachethecontent_posttypes`.

The cache key prefix is now `_vip_ctc_`, short for Cache The Content.

The plugin code is organized into a bootstrap file, an `includes/` folder for content-cache functionality, and an `admin/` folder for admin settings and cache lifecycle hooks.
