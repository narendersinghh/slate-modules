<?php
/**
 * Checkout page.
 * @var string $title @var array $lines @var array $totals @var array $data @var array $errors @var array $providers @var bool $cancelled
 */
use SlateModules\Shop\Checkout;
use SlateModules\Shop\Money;
use SlateModules\Shop\Shop;

$err = static fn(string $k): string => isset($errors[$k]) ? '<small class="shop-error-text" id="err-' . e($k) . '">' . e($errors[$k]) . '</small>' : '';
$inv = static fn(string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="err-' . e($k) . '"' : '';
$requireAddress = (bool)Shop::setting('require_address');
$general = array_intersect_key($errors, array_flip(['cart', 'payment']));
?>
<div class="shop-checkout">
  <h1 class="shop-title"><?= e($title) ?></h1>
  <?php if ($cancelled): ?><p class="shop-notice" role="status">Payment was cancelled. Your cart is still here, you can try again.</p><?php endif; ?>
  <?php if (trim((string)Shop::setting('checkout_intro')) !== ''): ?><p class="shop-intro"><?= nl2br(e((string)Shop::setting('checkout_intro'))) ?></p><?php endif; ?>
  <?php foreach ($general as $msg): ?><p class="shop-error" role="alert"><?= e($msg) ?></p><?php endforeach; ?>
  <?php if ($errors && !$general && !isset($errors['provider'])): ?><p class="shop-error" role="alert">Please check the highlighted fields.</p><?php endif; ?>

  <form method="post" action="<?= e(Shop::checkoutUrl()) ?>" class="shop-checkout-form" novalidate>
    <?= \Slate\Csrf::field() ?>
    <div class="shop-columns">
      <div class="shop-col">
        <fieldset class="shop-fieldset">
          <legend>Your details</legend>
          <p class="shop-field"><label for="shop-name">Full name <span class="shop-req">*</span></label>
            <input class="shop-input" id="shop-name" name="name" value="<?= e($data['name']) ?>" autocomplete="name" required maxlength="120"<?= $inv('name') ?>><?= $err('name') ?></p>
          <p class="shop-field"><label for="shop-email">Email <span class="shop-req">*</span></label>
            <input class="shop-input" id="shop-email" type="email" name="email" value="<?= e($data['email']) ?>" autocomplete="email" required maxlength="191"<?= $inv('email') ?>><?= $err('email') ?></p>
          <p class="shop-field"><label for="shop-phone">Phone<?= Shop::setting('require_phone') ? ' <span class="shop-req">*</span>' : '' ?></label>
            <input class="shop-input" id="shop-phone" type="tel" name="phone" value="<?= e($data['phone']) ?>" autocomplete="tel" maxlength="40"<?= Shop::setting('require_phone') ? ' required' : '' ?><?= $inv('phone') ?>><?= $err('phone') ?></p>
        </fieldset>

        <fieldset class="shop-fieldset">
          <legend><?= $requireAddress ? 'Billing address' : 'Address (optional)' ?></legend>
          <?php foreach (Checkout::ADDRESS_FIELDS as $k => $label): $req = $requireAddress && in_array($k, ['address_1', 'city', 'postcode', 'country'], true); ?>
            <p class="shop-field shop-field-<?= e($k) ?>"><label for="shop-b-<?= e($k) ?>"><?= e($label) ?><?= $req ? ' <span class="shop-req">*</span>' : '' ?></label>
              <input class="shop-input" id="shop-b-<?= e($k) ?>" name="billing[<?= e($k) ?>]" value="<?= e($data['billing'][$k]) ?>" maxlength="191"
                autocomplete="<?= e(['address_1' => 'billing address-line1', 'address_2' => 'billing address-line2', 'city' => 'billing address-level2', 'state' => 'billing address-level1', 'postcode' => 'billing postal-code', 'country' => 'billing country-name'][$k]) ?>"<?= $req ? ' required' : '' ?><?= $inv('billing_' . $k) ?>><?= $err('billing_' . $k) ?></p>
          <?php endforeach; ?>
        </fieldset>

        <div class="shop-ship">
          <input type="checkbox" class="shop-ship-toggle" id="shop-ship-different" name="ship_different" value="1"<?= $data['ship_different'] ? ' checked' : '' ?>>
          <label for="shop-ship-different">Deliver to a different address</label>
          <fieldset class="shop-fieldset shop-ship-fields">
            <legend class="shop-sr">Delivery address</legend>
            <p class="shop-field"><label for="shop-s-name">Recipient name</label>
              <input class="shop-input" id="shop-s-name" name="shipping[name]" value="<?= e($data['shipping']['name']) ?>" maxlength="120" autocomplete="shipping name"<?= $inv('shipping_name') ?>><?= $err('shipping_name') ?></p>
            <?php foreach (Checkout::ADDRESS_FIELDS as $k => $label): ?>
              <p class="shop-field shop-field-<?= e($k) ?>"><label for="shop-s-<?= e($k) ?>"><?= e($label) ?></label>
                <input class="shop-input" id="shop-s-<?= e($k) ?>" name="shipping[<?= e($k) ?>]" value="<?= e($data['shipping'][$k]) ?>" maxlength="191"<?= $inv('shipping_' . $k) ?>><?= $err('shipping_' . $k) ?></p>
            <?php endforeach; ?>
          </fieldset>
        </div>

        <p class="shop-field"><label for="shop-notes">Order notes</label>
          <textarea class="shop-input" id="shop-notes" name="notes" rows="3" maxlength="2000"><?= e($data['notes']) ?></textarea></p>
      </div>

      <div class="shop-col shop-col-summary">
        <h2 class="shop-subtitle">Your order</h2>
        <table class="shop-table shop-table-compact">
          <tbody>
          <?php foreach ($lines as $l): ?>
            <tr<?= $l['problem'] ? ' class="shop-line-problem"' : '' ?>><td><?= e($l['title']) ?> <span class="shop-muted">× <?= (int)$l['qty'] ?></span></td><td class="shop-num"><?= e(Money::format($l['total'])) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <dl class="shop-totals">
          <dt>Subtotal</dt><dd><?= e(Money::format($totals['subtotal'])) ?></dd>
          <?php if ($totals['shipping'] > 0 || (string)Shop::setting('shipping_flat') !== ''): ?>
            <dt>Shipping</dt><dd><?= $totals['shipping'] > 0 ? e(Money::format($totals['shipping'])) : 'Free' ?></dd>
          <?php endif; ?>
          <dt class="shop-grand">Total</dt><dd class="shop-grand"><?= e(Money::format($totals['total'])) ?></dd>
        </dl>

        <?php if ($providers): ?>
        <fieldset class="shop-fieldset shop-providers">
          <legend>Payment</legend>
          <?php $selected = isset($providers[$data['provider']]) ? $data['provider'] : (string)array_key_first($providers); ?>
          <?php foreach ($providers as $slug => $label): ?>
            <label class="shop-provider"><input type="radio" name="provider" value="<?= e($slug) ?>"<?= $slug === $selected ? ' checked' : '' ?>> <?= e($label) ?></label>
          <?php endforeach; ?>
          <?= $err('provider') ?>
        </fieldset>
        <p><button type="submit" class="shop-button shop-button-wide"><?= e((string)Shop::setting('order_button_label') ?: 'Place order') ?></button></p>
        <?php else: ?>
          <p class="shop-error" role="alert"><?= e($errors['provider'] ?? 'Online ordering is not available right now.') ?></p>
        <?php endif; ?>
        <p><a class="shop-link" href="<?= e(Shop::cartUrl()) ?>">Back to cart</a></p>
      </div>
    </div>
  </form>
</div>
