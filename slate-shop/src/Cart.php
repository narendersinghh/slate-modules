<?php
declare(strict_types=1);

namespace SlateModules\Shop;

use Slate\Crypto;
use Slate\Posts;

/**
 * The cart lives in a signed cookie ("shop_cart": base64url(JSON {product id: qty}) + "." + HMAC), so no session
 * or database row is needed and anonymous product pages stay cacheable. A tampered or foreign cookie is ignored.
 * Prices are never stored in the cookie: they are always read from the products.
 */
final class Cart
{
    public const COOKIE = 'shop_cart';
    public const MAX_LINES = 50;
    public const MAX_QTY = 999;
    private const DAYS = 30;

    /** @var array<int, int>|null */
    private static ?array $items = null;

    /** @return array<int, int> product id => quantity */
    public static function items(): array
    {
        if (self::$items === null) {
            self::$items = self::read();
        }
        return self::$items;
    }

    public static function count(): int
    {
        return array_sum(self::items());
    }

    public static function isEmpty(): bool
    {
        return self::items() === [];
    }

    private static function sign(string $payload): string
    {
        return Crypto::hmac('shop_cart|' . $payload);
    }

    private static function read(): array
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($raw) || $raw === '' || strlen($raw) > 3000 || substr_count($raw, '.') !== 1) {
            return [];
        }
        [$payload, $sig] = explode('.', $raw, 2);
        try {
            if (!hash_equals(self::sign($payload), $sig)) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        $items = [];
        foreach ($data as $id => $qty) {
            if (is_int($qty) && ctype_digit((string)$id) && (int)$id > 0 && $qty > 0) {
                $items[(int)$id] = min(self::MAX_QTY, $qty);
            }
            if (count($items) >= self::MAX_LINES) {
                break;
            }
        }
        return $items;
    }

    /** @param array<int, int> $items */
    public static function save(array $items): void
    {
        $clean = [];
        foreach ($items as $id => $qty) {
            $id = (int)$id;
            $qty = (int)$qty;
            if ($id > 0 && $qty > 0 && count($clean) < self::MAX_LINES) {
                $clean[$id] = min(self::MAX_QTY, $qty);
            }
        }
        self::$items = $clean;
        $opts = ['path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax'];
        if (!$clean) {
            if (isset($_COOKIE[self::COOKIE]) && !headers_sent()) {
                setcookie(self::COOKIE, '', ['expires' => time() - 3600] + $opts);
            }
            unset($_COOKIE[self::COOKIE]);
            return;
        }
        $payload = rtrim(strtr(base64_encode((string)json_encode((object)$clean)), '+/', '-_'), '=');
        $value = $payload . '.' . self::sign($payload);
        if (!headers_sent()) {
            setcookie(self::COOKIE, $value, ['expires' => time() + self::DAYS * 86400] + $opts);
        }
        $_COOKIE[self::COOKIE] = $value;
    }

    public static function clear(): void
    {
        self::save([]);
    }

    /**
     * Add units of a product. Returns an error code (see message()) or null.
     */
    public static function add(int $id, int $qty): ?string
    {
        $post = $id > 0 ? Posts::find($id) : null;
        if (!Product::purchasable($post)) {
            return 'unavailable';
        }
        if ($qty < 1 || $qty > self::MAX_QTY) {
            return 'quantity';
        }
        $items = self::items();
        $current = $items[$id] ?? 0;
        if (Product::soldIndividually($post) && $current >= 1) {
            return 'individual';
        }
        if (!Product::inStock($post)) {
            return 'out_of_stock';
        }
        if (!isset($items[$id]) && count($items) >= self::MAX_LINES) {
            return 'full';
        }
        $wanted = $current + $qty;
        $max = Product::maxQty($post);
        if ($wanted > $max) {
            $items[$id] = $max;
            self::save($items);
            return 'limited';
        }
        $items[$id] = $wanted;
        self::save($items);
        return null;
    }

    /** Set the quantity of a line (0 removes it). Returns an error code or null. */
    public static function set(int $id, int $qty): ?string
    {
        $items = self::items();
        if (!isset($items[$id])) {
            return null;
        }
        if ($qty <= 0) {
            unset($items[$id]);
            self::save($items);
            return null;
        }
        $post = Posts::find($id);
        if (!Product::purchasable($post)) {
            unset($items[$id]);
            self::save($items);
            return 'unavailable';
        }
        $max = Product::maxQty($post);
        $error = null;
        if ($max < 1) {
            unset($items[$id]);
            self::save($items);
            return 'out_of_stock';
        }
        if ($qty > $max) {
            $qty = $max;
            $error = 'limited';
        }
        $items[$id] = $qty;
        self::save($items);
        return $error;
    }

    public static function remove(int $id): void
    {
        $items = self::items();
        unset($items[$id]);
        self::save($items);
    }

    /**
     * Cart lines with current product data.
     * @return array<int, array{id:int, post:?array, title:string, sku:string, qty:int, unit:int, total:int, url:string, problem:?string}>
     */
    public static function lines(): array
    {
        $items = self::items();
        if (!$items) {
            return [];
        }
        Posts::prime(array_keys($items));
        $lines = [];
        foreach ($items as $id => $qty) {
            $post = Posts::find($id);
            $problem = null;
            if (!Product::purchasable($post)) {
                $problem = 'unavailable';
            } elseif (!Product::inStock($post, 1)) {
                $problem = 'out_of_stock';
            } elseif ($qty > Product::maxQty($post)) {
                $problem = Product::soldIndividually($post) ? 'individual' : 'limited';
            }
            $unit = $post ? (Product::price($post) ?? 0) : 0;
            $lines[] = [
                'id' => $id,
                'post' => $post,
                'title' => $post ? (string)$post['title'] : 'Unavailable product',
                'sku' => $post ? trim((string)($post['fields']['sku'] ?? '')) : '',
                'qty' => $qty,
                'unit' => $unit,
                'total' => $unit * $qty,
                'url' => $post && $post['status'] === 'publish' ? Posts::permalink($post) : '',
                'problem' => $problem,
            ];
        }
        return $lines;
    }

    /** @return array{subtotal:int, shipping:int, total:int} */
    public static function totals(array $lines): array
    {
        $subtotal = 0;
        foreach ($lines as $l) {
            if ($l['problem'] === null) {
                $subtotal += $l['total'];
            }
        }
        $shipping = Shop::shippingFor($subtotal);
        return ['subtotal' => $subtotal, 'shipping' => $shipping, 'total' => $subtotal + $shipping];
    }

    public static function message(string $code): string
    {
        return match ($code) {
            'unavailable' => 'That product is no longer available.',
            'out_of_stock' => 'Sorry, that product is out of stock.',
            'limited' => 'We do not have that many in stock, so the quantity was reduced.',
            'individual' => 'This product can only be bought one at a time.',
            'quantity' => 'Please choose a valid quantity.',
            'full' => 'Your cart is full.',
            default => 'Something went wrong. Please try again.',
        };
    }
}
