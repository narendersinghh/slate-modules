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
// Settings and encrypted keys: one option each since 1.2 ("shop_currency", "shop_stripe_test_secret", …), one array each before.
$names = ['shop_settings', 'shop_secrets'];
foreach (['currency', 'currency_symbol', 'currency_position', 'decimals', 'thousand_sep', 'decimal_sep', 'shop_email', 'shipping_flat',
    'free_shipping_over', 'require_address', 'require_phone', 'manual_enabled', 'manual_title', 'manual_instructions', 'stripe_enabled', 'stripe_mode',
    'stripe_title', 'razorpay_enabled', 'razorpay_mode', 'razorpay_title', 'razorpay_test_key_id', 'razorpay_live_key_id', 'auto_append', 'after_add',
    'cart_title', 'cart_empty', 'checkout_title', 'checkout_intro', 'thankyou_text', 'add_to_cart_label', 'order_button_label',
    'stripe_test_secret', 'stripe_test_webhook', 'stripe_live_secret', 'stripe_live_webhook',
    'razorpay_test_key_secret', 'razorpay_test_webhook', 'razorpay_live_key_secret', 'razorpay_live_webhook'] as $key) {
    $names[] = str_starts_with($key, 'shop_') ? $key : 'shop_' . $key;
}
foreach ($names as $name) {
    Options::delete($name);
}

// Stop bypassing the page cache for the cart cookie.
$cookies = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)Options::get('cache_bypass_cookies', '')) ?: []),
    static fn(string $c): bool => $c !== '' && $c !== 'shop_*'));
Options::set('cache_bypass_cookies', implode(', ', $cookies));
PageCache::writeFlag();
