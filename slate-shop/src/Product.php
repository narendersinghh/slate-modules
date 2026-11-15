<?php
declare(strict_types=1);

namespace SlateModules\Shop;

use Slate\DB;
use Slate\Frontend;
use Slate\PageCache;
use Slate\Posts;
use Slate\Templates;

/** Product data (prices, stock) and the product HTML snippets: price, add to cart form, gallery. */
final class Product
{
    public const TYPE = 'product';

    /** Ids whose add to cart form (or price) was already printed on the current single product view. */
    private static array $printed = ['form' => [], 'price' => []];

    /** @return array{regular:?int, sale:?int, price:?int} minor units; price is what the customer pays */
    public static function prices(array $post): array
    {
        $regular = Money::toMinor($post['fields']['price'] ?? null);
        $sale = Money::toMinor($post['fields']['sale_price'] ?? null);
        if ($regular === null || ($sale !== null && $sale >= $regular)) {
            $sale = null;
        }
        return ['regular' => $regular, 'sale' => $sale, 'price' => $regular === null ? null : ($sale ?? $regular)];
    }

    public static function price(array $post): ?int
    {
        return self::prices($post)['price'];
    }

    /** Units in stock, null = unlimited. */
    public static function stock(array $post): ?int
    {
        $s = $post['fields']['stock'] ?? null;
        if ($s === null || $s === '' || !is_numeric($s)) {
            return null;
        }
        return max(0, (int)$s);
    }

    public static function soldIndividually(array $post): bool
    {
        return !empty($post['fields']['sold_individually']);
    }

    public static function isProduct(?array $post): bool
    {
        return $post !== null && ($post['type'] ?? null) === self::TYPE;
    }

    /** Can be bought right now (published, has a price). Stock is checked separately. */
    public static function purchasable(?array $post): bool
    {
        return self::isProduct($post) && ($post['status'] ?? '') === 'publish' && self::price($post) !== null;
    }

    public static function inStock(array $post, int $qty = 1): bool
    {
        $stock = self::stock($post);
        return $stock === null || $stock >= $qty;
    }

    /** Most units one order may contain. */
    public static function maxQty(array $post): int
    {
        if (self::soldIndividually($post)) {
            return 1;
        }
        $stock = self::stock($post);
        return $stock === null ? Cart::MAX_QTY : min(Cart::MAX_QTY, $stock);
    }

    // ------------------------------------------------------------------ HTML

    private static function isCurrentSingle(array $post): bool
    {
        return (Frontend::$data['kind'] ?? '') === 'single' && (int)(Frontend::$data['post']['id'] ?? 0) === (int)$post['id'];
    }

    public static function priceHtml(?array $post): string
    {
        if (!self::isProduct($post)) {
            return '';
        }
        $p = self::prices($post);
        if ($p['price'] === null) {
            return '';
        }
        if (self::isCurrentSingle($post)) {
            self::$printed['price'][(int)$post['id']] = true;
        }
        if ($p['sale'] !== null && $p['regular'] !== null) {
            return '<span class="shop-price shop-price-sale"><del><span class="shop-sr">Regular price </span>' . e(Money::format($p['regular'])) . '</del> '
                . '<ins><span class="shop-sr">Sale price </span>' . e(Money::format($p['sale'])) . '</ins></span>';
        }
        return '<span class="shop-price">' . e(Money::format($p['price'])) . '</span>';
    }

    public static function addToCartHtml(?array $post): string
    {
        if (!self::purchasable($post)) {
            return '';
        }
        $id = (int)$post['id'];
        if (self::isCurrentSingle($post)) {
            if (isset(self::$printed['form'][$id])) {
                return ''; // already on this page (theme helper + automatic block): never twice
            }
            self::$printed['form'][$id] = true;
        }
        if (!self::inStock($post)) {
            return '<p class="shop-stock shop-stock-out">Out of stock</p>';
        }
        $max = self::maxQty($post);
        $stock = self::stock($post);
        $html = '';
        if ((int)($_GET['added'] ?? 0) === $id) {
            $html .= '<p class="shop-notice" role="status">Added to your cart. <a href="' . e(Shop::cartUrl()) . '">View cart</a></p>';
        }
        $html .= '<form class="shop-add-to-cart" method="post" action="' . e(url('/cart/add')) . '">'
            . '<input type="hidden" name="product_id" value="' . $id . '">';
        if ($max > 1) {
            $html .= '<label class="shop-qty"><span class="shop-sr">Quantity</span>'
                . '<input type="number" name="qty" value="1" min="1" max="' . $max . '" step="1" inputmode="numeric" required></label>';
        } else {
            $html .= '<input type="hidden" name="qty" value="1">';
        }
        $html .= '<button type="submit" class="shop-button">' . e((string)Shop::setting('add_to_cart_label') ?: 'Add to cart') . '</button></form>';
        if ($stock !== null && $stock <= 5) {
            $html .= '<p class="shop-stock shop-stock-low">Only ' . $stock . ' left</p>';
        }
        return $html;
    }

    public static function galleryHtml(?array $post): string
    {
        if (!self::isProduct($post)) {
            return '';
        }
        $ids = array_values(array_filter(array_map('intval', (array)($post['fields']['gallery'] ?? []))));
        if (!$ids) {
            return '';
        }
        \Slate\Media::prime($ids);
        $out = '';
        foreach ($ids as $mid) {
            $full = media_url($mid);
            if ($full === '') {
                continue;
            }
            $out .= '<a class="shop-gallery-item" href="' . e($full) . '">' . image($mid, ['size' => 'thumb', 'sizes' => '160px']) . '</a>';
        }
        return $out === '' ? '' : '<div class="shop-gallery">' . $out . '</div>';
    }

    /** Block appended to single product content (setting "auto_append"). */
    public static function appendToContent(string $html, array $post): string
    {
        if (!Shop::setting('auto_append') || !self::isProduct($post) || !self::isCurrentSingle($post)) {
            return $html;
        }
        $id = (int)$post['id'];
        if (isset(self::$printed['form'][$id]) || str_contains($html, 'shop-add-to-cart')) {
            return $html;
        }
        // An admin-built template that places the shop tags itself gets no automatic block.
        $tpl = Templates::resolve('single', self::TYPE . '__' . ($post['slug'] ?? ''));
        if ($tpl && preg_match('/\{\{\{?\s*shop:(add_to_cart|price)/', (string)$tpl['html'])) {
            return $html;
        }
        $price = isset(self::$printed['price'][$id]) ? '' : self::priceHtml($post);
        $note = trim((string)($post['fields']['short_note'] ?? ''));
        $block = '<div class="shop-product">'
            . ($price !== '' ? '<p class="shop-product-price">' . $price . '</p>' : '')
            . ($note !== '' ? '<p class="shop-note">' . nl2br(e($note)) . '</p>' : '')
            . self::addToCartHtml($post)
            . self::galleryHtml($post)
            . '</div>';
        return $html . $block;
    }

    // ------------------------------------------------------------------ data upkeep

    /** post_before_save: store prices as canonical decimal strings and stock as a whole number (or unlimited). */
    public static function normalizeRow(array $row): array
    {
        if (($row['type'] ?? null) !== self::TYPE || !isset($row['fields'])) {
            return $row;
        }
        $fields = json_decode((string)$row['fields'], true);
        if (!is_array($fields)) {
            return $row;
        }
        foreach (['price', 'sale_price'] as $k) {
            if (array_key_exists($k, $fields)) {
                $fields[$k] = Money::normalize($fields[$k]);
            }
        }
        if (array_key_exists('stock', $fields)) {
            $s = $fields['stock'];
            $fields['stock'] = ($s === null || $s === '' || !is_numeric($s)) ? null : max(0, (int)$s);
        }
        $row['fields'] = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $row;
    }

    /**
     * Take units out of stock (never below zero). Compare-and-swap on the fields column, with a locking read on
     * MySQL, so concurrent orders cannot overwrite each other's decrement. Returns the shortfall (units sold
     * beyond what was in stock), 0 normally.
     */
    public static function decrementStock(int $id, int $qty): int
    {
        if ($qty <= 0) {
            return 0;
        }
        $lock = DB::$driver === 'mysql' ? ' FOR UPDATE' : '';
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $old = DB::val('SELECT fields FROM {{posts}} WHERE id = :id' . $lock, ['id' => $id]);
            if ($old === null) {
                return 0;
            }
            $fields = json_decode((string)$old, true);
            if (!is_array($fields) || !isset($fields['stock']) || $fields['stock'] === '' || !is_numeric($fields['stock'])) {
                return 0; // unlimited
            }
            $stock = max(0, (int)$fields['stock']);
            if ($stock === 0) {
                return $qty; // already empty
            }
            $new = max(0, $stock - $qty);
            $fields['stock'] = $new;
            $json = (string)json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $n = DB::query('UPDATE {{posts}} SET fields = :new WHERE id = :id AND fields = :old', ['new' => $json, 'id' => $id, 'old' => (string)$old])->rowCount();
            if ($n === 1) {
                Posts::flush($id);
                return max(0, $qty - $stock);
            }
        }
        error_log('Shop: could not update stock of product ' . $id . ' (concurrent updates).');
        return 0;
    }

    /** Refresh cached pages of products whose stock changed. */
    public static function purgePages(array $ids): void
    {
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            Posts::flush($id);
            $post = Posts::find($id);
            if ($post) {
                PageCache::purgeContent($post);
            }
        }
    }
}
