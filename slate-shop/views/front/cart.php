<?php
/**
 * Cart page.
 * @var string $title @var array $lines @var array $totals @var ?string $notice @var ?string $error
 */
use SlateModules\Shop\Cart;
use SlateModules\Shop\Money;
use SlateModules\Shop\Shop;

?>
<div class="shop-cart">
  <h1 class="shop-title"><?= e($title) ?></h1>
  <?php if ($notice): ?><p class="shop-notice" role="status"><?= e($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="shop-error" role="alert"><?= e($error) ?></p><?php endif; ?>

  <?php if (!$lines): ?>
    <p class="shop-empty"><?= e((string)Shop::setting('cart_empty') ?: 'Your cart is empty.') ?></p>
    <p><a class="shop-button" href="<?= e(Shop::shopUrl()) ?>">Continue shopping</a></p>
  <?php else: ?>
  <form method="post" action="<?= e(url('/cart/update')) ?>" class="shop-cart-form">
    <div class="shop-table-wrap">
      <table class="shop-table">
        <thead><tr><th scope="col">Product</th><th scope="col" class="shop-num">Price</th><th scope="col">Quantity</th><th scope="col" class="shop-num">Total</th><th scope="col"><span class="shop-sr">Remove</span></th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): $max = $l['post'] ? SlateModules\Shop\Product::maxQty($l['post']) : 0; ?>
          <tr<?= $l['problem'] ? ' class="shop-line-problem"' : '' ?>>
            <td data-label="Product">
              <?php if ($l['post'] && $l['post']['featured_media_id']): ?><span class="shop-thumb"><?= image((int)$l['post']['featured_media_id'], ['size' => 'thumb', 'sizes' => '64px', 'alt' => '']) ?></span><?php endif; ?>
              <?= $l['url'] !== '' ? '<a href="' . e($l['url']) . '">' . e($l['title']) . '</a>' : e($l['title']) ?>
              <?php if ($l['sku'] !== ''): ?><small class="shop-sku">SKU: <?= e($l['sku']) ?></small><?php endif; ?>
              <?php if ($l['problem']): ?><small class="shop-error-text"><?= e(Cart::message($l['problem'])) ?></small><?php endif; ?>
            </td>
            <td data-label="Price" class="shop-num"><?= e(Money::format($l['unit'])) ?></td>
            <td data-label="Quantity">
              <?php if ($max > 1): ?>
                <label><span class="shop-sr">Quantity of <?= e($l['title']) ?></span>
                <input class="shop-input shop-qty-input" type="number" name="qty[<?= (int)$l['id'] ?>]" value="<?= (int)$l['qty'] ?>" min="0" max="<?= $max ?>" step="1" inputmode="numeric"></label>
              <?php else: ?>
                <input type="hidden" name="qty[<?= (int)$l['id'] ?>]" value="<?= min((int)$l['qty'], max($max, 0)) ?>"><?= (int)$l['qty'] ?>
              <?php endif; ?>
            </td>
            <td data-label="Total" class="shop-num"><?= e(Money::format($l['total'])) ?></td>
            <td><button type="submit" class="shop-link-button" formaction="<?= e(url('/cart/remove')) ?>" name="product_id" value="<?= (int)$l['id'] ?>" aria-label="Remove <?= e($l['title']) ?>">Remove</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="shop-actions-left"><button type="submit" class="shop-button shop-button-secondary">Update cart</button></p>
  </form>

  <div class="shop-summary">
    <dl class="shop-totals">
      <dt>Subtotal</dt><dd><?= e(Money::format($totals['subtotal'])) ?></dd>
      <?php if ($totals['shipping'] > 0 || (string)Shop::setting('shipping_flat') !== ''): ?>
        <dt>Shipping</dt><dd><?= $totals['shipping'] > 0 ? e(Money::format($totals['shipping'])) : 'Free' ?></dd>
      <?php endif; ?>
      <dt class="shop-grand">Total</dt><dd class="shop-grand"><?= e(Money::format($totals['total'])) ?></dd>
    </dl>
    <?php $free = Money::toMinor((string)Shop::setting('free_shipping_over')); if ($free && $totals['shipping'] > 0 && $totals['subtotal'] < $free): ?>
      <p class="shop-hint">Spend <?= e(Money::format($free - $totals['subtotal'])) ?> more for free shipping.</p>
    <?php endif; ?>
    <p class="shop-actions">
      <a class="shop-link" href="<?= e(Shop::shopUrl()) ?>">Continue shopping</a>
      <a class="shop-button" href="<?= e(Shop::checkoutUrl()) ?>">Proceed to checkout</a>
    </p>
  </div>
  <?php endif; ?>
</div>
