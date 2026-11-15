<?php
/**
 * Removes the Shop's own data: the orders table and its settings. Products and product categories are ordinary
 * content and stay (delete them under Content if you no longer need them).
 */
declare(strict_types=1);

use Slate\DB;
use Slate\Options;
use Slate\PageCache;

DB::exec('DROP TABLE IF EXISTS {{shop_orders}}');
foreach (['shop_settings', 'shop_secrets'] as $name) {
    Options::delete($name);
}

// Stop bypassing the page cache for the cart cookie.
$cookies = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)Options::get('cache_bypass_cookies', '')) ?: []),
    static fn(string $c): bool => $c !== '' && $c !== 'shop_*'));
Options::set('cache_bypass_cookies', implode(', ', $cookies));
PageCache::writeFlag();
