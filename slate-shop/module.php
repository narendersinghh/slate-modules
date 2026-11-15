<?php
/**
 * Shop — products, signed-cookie cart, checkout, orders, Stripe Checkout / Razorpay / manual payments.
 */
declare(strict_types=1);

use Slate\Hooks;
use Slate\Modules;
use Slate\Options;
use Slate\Router;
use SlateModules\Shop\Admin;
use SlateModules\Shop\Cart;
use SlateModules\Shop\Checkout;
use SlateModules\Shop\Orders;
use SlateModules\Shop\Product;
use SlateModules\Shop\Shop;

// Classes are autoloaded through module.json; this fallback keeps the module working if the autoload map is stale.
spl_autoload_register(static function (string $class): void {
    $prefix = 'SlateModules\\Shop\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

Options::lazy(Shop::SECRETS);

// ---------------------------------------------------------------- lifecycle
Hooks::add('module_activated_slate-shop', [Shop::class, 'ensureCacheBypass']);
Hooks::add('module_updated_slate-shop', static fn() => Shop::ensureCacheBypass());

// ---------------------------------------------------------------- content model (only when the site has none of its own)
Hooks::add('post_types', static function (array $types): array {
    if (!isset($types[Product::TYPE])) {
        $types[Product::TYPE] = Shop::postType();
    }
    return $types;
});
Hooks::add('taxonomies', static function (array $taxes): array {
    if (!isset($taxes['product_category'])) {
        $taxes['product_category'] = Shop::taxonomy();
    }
    return $taxes;
});
Hooks::add('field_groups', static function (array $groups): array {
    $group = Shop::fieldGroup();
    if (!isset($groups[$group['id']])) {
        $groups[$group['id']] = $group;
    }
    return $groups;
});
Hooks::add('post_before_save', [Product::class, 'normalizeRow']);

// ---------------------------------------------------------------- front end
Hooks::add('routes', [Checkout::class, 'routes']);
Hooks::add('the_content', [Product::class, 'appendToContent'], 20);
Hooks::add('head', static function (): void {
    if (Shop::wantsAssets()) {
        echo '<link rel="stylesheet" href="' . e(Shop::assetUrl('shop.css')) . '">' . "\n";
    }
});
Hooks::add('seo_robots', static fn(string $robots, array $ctx): string => !empty($ctx['shop_page']) ? 'noindex, nofollow' : $robots);

// Template tags: {{shop:price}} {{shop:add_to_cart}} {{shop:gallery}} {{shop:cart_count}} {{shop:cart_url}}
Hooks::add('template_tag', static function (mixed $value, string $name, string $arg, array $ctx = []): mixed {
    if ($value !== null || $name !== 'shop') {
        return $value;
    }
    $post = $ctx['post'] ?? null;
    return match ($arg) {
        'price' => Product::priceHtml(is_array($post) ? $post : null),
        'add_to_cart' => Product::addToCartHtml(is_array($post) ? $post : null),
        'gallery' => Product::galleryHtml(is_array($post) ? $post : null),
        'cart_count' => (string)Cart::count(),
        'cart_url' => e(Shop::cartUrl()),
        default => null,
    };
});

// Theme helpers.
if (!function_exists('shop_price')) {
    function shop_price(?array $post = null): string
    {
        return Product::priceHtml($post ?? post());
    }
}
if (!function_exists('shop_add_to_cart')) {
    function shop_add_to_cart(?array $post = null): string
    {
        return Product::addToCartHtml($post ?? post());
    }
}
if (!function_exists('shop_gallery')) {
    function shop_gallery(?array $post = null): string
    {
        return Product::galleryHtml($post ?? post());
    }
}
if (!function_exists('shop_cart_count')) {
    function shop_cart_count(): int
    {
        return Cart::count();
    }
}
if (!function_exists('shop_cart_url')) {
    function shop_cart_url(): string
    {
        return Shop::cartUrl();
    }
}

// ---------------------------------------------------------------- REST API
Hooks::add('api_post', static function (array $out, array $post, bool $full = false): array {
    if (Product::isProduct($post)) {
        $p = Product::prices($post);
        $out['price'] = $p['regular'] === null ? null : SlateModules\Shop\Money::toDecimal($p['regular']);
        $out['sale_price'] = $p['sale'] === null ? null : SlateModules\Shop\Money::toDecimal($p['sale']);
        $out['currency'] = SlateModules\Shop\Money::currency();
        $out['in_stock'] = Product::purchasable($post) && Product::inStock($post);
    }
    return $out;
});

// ---------------------------------------------------------------- webhooks, scheduled task
Hooks::add('webhook_events', static fn(array $events): array => $events + [
    'shop.order_created' => 'Shop: order created',
    'shop.order_paid' => 'Shop: order paid',
    'shop.order_status_changed' => 'Shop: order status changed',
]);
Hooks::add('cron_jobs', static fn(array $jobs): array => $jobs + [
    'shop_cancel_unpaid' => ['every' => 3600, 'label' => 'Shop: cancel unpaid online orders after ' . Orders::UNPAID_HOURS . ' hours',
        'run' => static fn() => Orders::cancelStale()],
]);

// ---------------------------------------------------------------- admin
Modules::adminPage('Orders', Admin::ORDERS, static function (): void {
    echo Shop::view(isset($_GET['id']) ? 'admin/order' : 'admin/orders');
}, 'edit_others_posts', 'shopping', 'content', [Orders::class, 'newCount']);

Modules::adminPage('Shop settings', Admin::SETTINGS, static function (): void {
    echo Shop::view('admin/settings');
}, 'manage_settings', 'settings', 'build');

Hooks::add('admin_routes', static function (Router $r): void {
    $r->post('/m/' . Admin::ORDERS . '/action', [Admin::class, 'orderAction']);
    $r->post('/m/' . Admin::SETTINGS . '/save', [Admin::class, 'saveSettings']);
});
