<?php
declare(strict_types=1);

namespace SlateModules\Shop;

use Slate\Auth;
use Slate\ContentModel;
use Slate\Crypto;
use Slate\Frontend;
use Slate\Options;
use Slate\PageCache;
use Slate\Posts;
use Slate\Seo;
use Slate\Theme;

/** Settings, content model, URLs and page rendering shared by the rest of the Shop. */
final class Shop
{
    public const SETTINGS = 'shop_settings';
    public const SECRETS = 'shop_secrets';
    public const BYPASS_COOKIE = 'shop_*';

    public const DEFAULTS = [
        'currency' => 'USD', 'currency_symbol' => '$', 'currency_position' => 'before', 'decimals' => 2,
        'thousand_sep' => ',', 'decimal_sep' => '.',
        'shop_email' => '',
        'shipping_flat' => '', 'free_shipping_over' => '',
        'require_address' => true, 'require_phone' => false,
        'manual_enabled' => true, 'manual_title' => 'Bank transfer / cash on delivery',
        'manual_instructions' => 'Please pay the order total by bank transfer or in cash on delivery. We will confirm your order by email once payment is received.',
        'stripe_enabled' => false, 'stripe_mode' => 'test', 'stripe_title' => 'Credit or debit card',
        'razorpay_enabled' => false, 'razorpay_mode' => 'test', 'razorpay_title' => 'UPI, cards, netbanking & wallets',
        'razorpay_test_key_id' => '', 'razorpay_live_key_id' => '',
        'auto_append' => true, 'after_add' => 'cart',
        'cart_title' => 'Your cart', 'cart_empty' => 'Your cart is empty.',
        'checkout_title' => 'Checkout', 'checkout_intro' => '',
        'thankyou_text' => 'Thank you! We have received your order.',
        'add_to_cart_label' => 'Add to cart', 'order_button_label' => 'Place order',
    ];

    /** Encrypted secrets (stored in a separate, non-autoloaded option). */
    public const SECRET_KEYS = [
        'stripe_test_secret' => 'Stripe test secret key (sk_test_…)',
        'stripe_test_webhook' => 'Stripe test webhook signing secret (whsec_…)',
        'stripe_live_secret' => 'Stripe live secret key (sk_live_…)',
        'stripe_live_webhook' => 'Stripe live webhook signing secret (whsec_…)',
        'razorpay_test_key_secret' => 'Razorpay test key secret',
        'razorpay_test_webhook' => 'Razorpay test webhook secret',
        'razorpay_live_key_secret' => 'Razorpay live key secret',
        'razorpay_live_webhook' => 'Razorpay live webhook secret',
    ];

    /** True while a shop page (cart, checkout, order) is rendered. */
    public static bool $shopPage = false;

    private static ?array $settings = null;
    private static ?array $secrets = null;

    // ------------------------------------------------------------------ settings

    public static function settings(): array
    {
        if (self::$settings === null) {
            $stored = Options::get(self::SETTINGS, []);
            self::$settings = (is_array($stored) ? $stored : []) + self::DEFAULTS;
        }
        return self::$settings;
    }

    public static function setting(string $key): mixed
    {
        return self::settings()[$key] ?? null;
    }

    public static function saveSettings(array $settings): void
    {
        $clean = array_intersect_key($settings, self::DEFAULTS);
        Options::set(self::SETTINGS, $clean);
        self::$settings = null;
    }

    public static function secret(string $key): string
    {
        if (self::$secrets === null) {
            $stored = Options::get(self::SECRETS, []);
            self::$secrets = is_array($stored) ? $stored : [];
        }
        try {
            return trim(Crypto::decrypt((string)(self::$secrets[$key] ?? '')));
        } catch (\Throwable) {
            return '';
        }
    }

    public static function hasSecret(string $key): bool
    {
        return self::secret($key) !== '';
    }

    /** @param array<string, string|null> $changes plain values; '' or null removes a secret */
    public static function saveSecrets(array $changes): void
    {
        $stored = Options::get(self::SECRETS, []);
        $stored = is_array($stored) ? $stored : [];
        foreach ($changes as $key => $plain) {
            if (!isset(self::SECRET_KEYS[$key])) {
                continue;
            }
            if ($plain === null || $plain === '') {
                unset($stored[$key]);
            } else {
                $stored[$key] = Crypto::encrypt($plain);
            }
        }
        Options::set(self::SECRETS, $stored, false);
        self::$secrets = null;
    }

    /** Payment methods offered at checkout: slug => label. */
    public static function providers(): array
    {
        $out = [];
        if (self::setting('manual_enabled')) {
            $out['manual'] = (string)self::setting('manual_title') ?: 'Manual payment';
        }
        if (self::setting('stripe_enabled') && Payments\Stripe::configured()) {
            $out['stripe'] = (string)self::setting('stripe_title') ?: 'Card';
        }
        if (self::setting('razorpay_enabled') && Payments\Razorpay::configured()) {
            $out['razorpay'] = (string)self::setting('razorpay_title') ?: 'Razorpay';
        }
        return $out;
    }

    public static function notifyEmail(): string
    {
        $email = trim((string)self::setting('shop_email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = trim((string)Options::get('admin_email', ''));
        }
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /** Shipping for a subtotal (minor units). */
    public static function shippingFor(int $subtotal): int
    {
        if ($subtotal <= 0) {
            return 0;
        }
        $flat = Money::toMinor((string)self::setting('shipping_flat')) ?? 0;
        $free = Money::toMinor((string)self::setting('free_shipping_over'));
        if ($free !== null && $free > 0 && $subtotal >= $free) {
            return 0;
        }
        return $flat;
    }

    // ------------------------------------------------------------------ content model

    public static function postType(): array
    {
        return [
            'slug' => Product::TYPE, 'singular' => 'Product', 'plural' => 'Products', 'icon' => 'shopping', 'public' => true,
            'has_archive' => true, 'hierarchical' => false, 'show_in_menu' => true, 'rewrite' => 'shop', 'menu_position' => 25,
            'supports' => ['title', 'editor', 'excerpt', 'featured_image', 'seo'], 'description' => 'Things you sell.', 'builtin' => false,
        ];
    }

    public static function taxonomy(): array
    {
        return [
            'slug' => 'product_category', 'singular' => 'Product category', 'plural' => 'Product categories', 'post_types' => [Product::TYPE],
            'hierarchical' => true, 'public' => true, 'rewrite' => 'shop/category', 'builtin' => false,
        ];
    }

    public static function fieldGroup(): array
    {
        return [
            'id' => 'shopproduct',
            'title' => 'Product',
            'position' => 'main',
            'location' => ['post_types' => [Product::TYPE], 'taxonomies' => []],
            'fields' => [
                ['name' => 'price', 'label' => 'Price', 'type' => 'text', 'required' => true, 'width' => '33', 'placeholder' => '19.99',
                    'help' => 'Regular price, without the currency symbol.', 'default' => '', 'options' => []],
                ['name' => 'sale_price', 'label' => 'Sale price', 'type' => 'text', 'required' => false, 'width' => '33', 'placeholder' => '',
                    'help' => 'Optional. Used while it is lower than the regular price.', 'default' => '', 'options' => []],
                ['name' => 'sku', 'label' => 'SKU', 'type' => 'text', 'required' => false, 'width' => '33', 'placeholder' => '',
                    'help' => 'Your own stock-keeping code.', 'default' => '', 'options' => []],
                ['name' => 'stock', 'label' => 'Stock', 'type' => 'number', 'required' => false, 'width' => '33', 'placeholder' => 'Unlimited',
                    'help' => 'Items available. Leave empty for unlimited.', 'default' => '', 'options' => []],
                ['name' => 'sold_individually', 'label' => 'Sold individually (one per order)', 'type' => 'toggle', 'required' => false, 'width' => '66',
                    'placeholder' => '', 'help' => '', 'default' => '', 'options' => []],
                ['name' => 'gallery', 'label' => 'Gallery', 'type' => 'gallery', 'required' => false, 'width' => '100', 'placeholder' => '',
                    'help' => 'More product photos.', 'default' => '', 'options' => []],
                ['name' => 'short_note', 'label' => 'Short note', 'type' => 'textarea', 'required' => false, 'width' => '100', 'placeholder' => '',
                    'help' => 'Shown next to the add to cart button, e.g. “Ships in 2–3 days”.', 'default' => '', 'options' => []],
            ],
        ];
    }

    // ------------------------------------------------------------------ URLs

    public static function cartUrl(bool $absolute = false): string
    {
        return self::link('/cart', $absolute);
    }

    public static function checkoutUrl(bool $absolute = false): string
    {
        return self::link('/checkout', $absolute);
    }

    public static function orderUrl(string $key, bool $absolute = false): string
    {
        return self::link('/order/' . $key, $absolute);
    }

    public static function shopUrl(): string
    {
        $type = ContentModel::type(Product::TYPE);
        $base = trim((string)($type['rewrite'] ?? 'shop'), '/');
        return !empty($type['has_archive']) && $base !== '' && !str_contains($base, '%') ? self::link('/' . $base) : url('/');
    }

    public static function webhookUrl(string $provider): string
    {
        return site_url('shop/webhook/' . $provider);
    }

    private static function link(string $path, bool $absolute = false): string
    {
        $path = Posts::trail($path);
        return $absolute ? site_url($path) : url($path);
    }

    public static function modulePath(string $file = ''): string
    {
        return dirname(__DIR__) . ($file !== '' ? '/' . ltrim($file, '/') : '');
    }

    public static function assetUrl(string $file): string
    {
        return asset_url('modules/' . basename(dirname(__DIR__)) . '/assets/' . ltrim($file, '/'));
    }

    // ------------------------------------------------------------------ request guards

    /**
     * Same-origin check for shop POSTs. Cart forms sit on cacheable product pages, so they cannot carry a session
     * token; browsers always send Origin (or at least Referer) with cross-site form posts, so a foreign or "null"
     * origin is refused. Requests without either header do not come from a browser and cannot ride on a
     * visitor's cookies (which are SameSite=Lax anyway).
     */
    public static function sameOrigin(): bool
    {
        $source = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($source === '') {
            $source = (string)($_SERVER['HTTP_REFERER'] ?? '');
        }
        if ($source === '') {
            return true;
        }
        $host = strtolower((string)parse_url($source, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        $allowed = [
            strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)),
            strtolower((string)parse_url(site_url(), PHP_URL_HOST)),
        ];
        return in_array($host, array_filter($allowed), true);
    }

    public static function requireSameOrigin(): void
    {
        if (!self::sameOrigin()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            exit('This request was blocked because it came from another website. Go back and try again.');
        }
    }

    /** Keep shoppers with a cart (cookie "shop_cart") out of the page cache. */
    public static function ensureCacheBypass(): void
    {
        $list = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)Options::get('cache_bypass_cookies', '')) ?: []), 'strlen'));
        if (!in_array(self::BYPASS_COOKIE, $list, true) && !in_array(Cart::COOKIE, $list, true)) {
            $list[] = self::BYPASS_COOKIE;
            Options::set('cache_bypass_cookies', implode(', ', $list));
        }
        PageCache::writeFlag();
    }

    // ------------------------------------------------------------------ rendering

    /** Render a view file from views/ and return the HTML. */
    public static function view(string $name, array $vars = []): string
    {
        $file = self::modulePath('views/' . preg_replace('#[^a-z0-9/_-]#', '', $name) . '.php');
        return (static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string)ob_get_clean();
        })($file, $vars);
    }

    /** Shop pages are never cached and never indexed. */
    public static function noCache(): void
    {
        PageCache::skip();
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('X-Robots-Tag: noindex, nofollow');
        }
    }

    /** Sites in maintenance mode show their maintenance page instead of the shop. */
    public static function maintenanceGuard(): void
    {
        if (Options::get('maintenance_mode') && !Auth::can('access_admin')) {
            http_response_code(503);
            header('Retry-After: 3600');
            Seo::$ctx = ['kind' => '404'];
            Frontend::$data['kind'] = 'maintenance';
            Theme::render(['maintenance'], ['message' => Options::get('maintenance_message')]);
            exit;
        }
    }

    /**
     * Render a full page inside the active theme through core's output pipeline. A theme can provide its own
     * templates/shop-<view>.php (it gets the same variables plus the rendered $shop_content); otherwise the
     * content is placed between the theme's header and footer by Frontend::content().
     */
    public static function page(string $view, string $title, array $vars = [], int $status = 200): never
    {
        self::$shopPage = true;
        self::noCache();
        http_response_code($status);
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        Seo::$ctx = ['kind' => 'archive', 'title' => $title, 'url' => site_url(substr($path, strlen(base_path()))), 'page' => 1, 'shop_page' => true];
        $data = ['title' => $title, 'shop_view' => $view];
        Frontend::$data = array_merge(Frontend::$data, $data, ['kind' => 'shop']);

        $content = self::view('front/' . $view, $vars + ['title' => $title]);
        $wrapped = '<div class="wrap shop-page shop-page-' . e($view) . '">' . $content . '</div>';
        if (is_file(Theme::path('templates/shop-' . $view . '.php'))) {
            Frontend::page('shop', $vars + $data + ['shop_content' => $content], ['shop-' . $view]);
        } else {
            Frontend::content('shop', $wrapped, $data);
        }
        exit;
    }

    /** Should shop.css load on the current page? */
    public static function wantsAssets(): bool
    {
        if (self::$shopPage) {
            return true;
        }
        $d = Frontend::$data;
        if (($d['post']['type'] ?? null) === Product::TYPE && ($d['kind'] ?? '') === 'single') {
            return true;
        }
        if (($d['kind'] ?? '') === 'archive' && (($d['type']['slug'] ?? null) === Product::TYPE)) {
            return true;
        }
        return ($d['kind'] ?? '') === 'taxonomy' && (($d['taxonomy']['slug'] ?? null) === 'product_category'
            || in_array(Product::TYPE, (array)($d['taxonomy']['post_types'] ?? []), true));
    }
}
