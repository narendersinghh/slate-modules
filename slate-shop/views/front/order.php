<?php
/**
 * Order status / thank-you page (reachable only with the order's secret key).
 * @var string $title @var array $order @var ?string $message
 */
use SlateModules\Shop\Orders;
use SlateModules\Shop\Payments\Razorpay;
use SlateModules\Shop\Shop;

$o = $order;
$status = $o['status'];
$paid = in_array($status, ['paid', 'processing', 'completed'], true);
$open = in_array($status, ['pending', 'failed'], true);
$self = Shop::orderUrl($o['order_key']);
$money = static fn(int $m): string => e(Orders::money($o, $m));
$addr = static function (?array $a): string {
    if (!$a) {
        return '';
    }
    $parts = array_filter([$a['name'] ?? '', $a['address_1'] ?? '', $a['address_2'] ?? '',
        trim(($a['postcode'] ?? '') . ' ' . ($a['city'] ?? '')), $a['state'] ?? '', $a['country'] ?? ''], static fn($v) => trim((string)$v) !== '');
    return implode('<br>', array_map('e', $parts));
};
?>
<div class="shop-order">
  <h1 class="shop-title"><?= e($title) ?> <span class="shop-status shop-status-<?= e($status) ?>"><?= e(Orders::label($status)) ?></span></h1>
  <?php if ($message): ?><p class="shop-error" role="alert"><?= e($message) ?></p><?php endif; ?>

  <?php if ($paid || ($status === 'pending' && $o['provider'] === 'manual')): ?>
    <p class="shop-notice" role="status"><?= nl2br(e((string)Shop::setting('thankyou_text'))) ?></p>
  <?php endif; ?>

  <?php if ($status === 'pending' && $o['provider'] === 'manual'): ?>
    <div class="shop-box">
      <h2 class="shop-subtitle">How to pay</h2>
      <p><?= nl2br(e((string)Shop::setting('manual_instructions'))) ?></p>
      <p>Please mention your order number <strong><?= e(Orders::number($o)) ?></strong> with your payment. Amount due: <strong><?= $money($o['total_minor']) ?></strong>.</p>
    </div>
  <?php elseif ($open && $o['provider'] === 'stripe'): ?>
    <div class="shop-box">
      <?php if ($status === 'pending' && isset($_GET['session_id'])): ?>
        <p>We are waiting for the payment confirmation. This usually takes a few seconds, <a href="<?= e($self) ?>">refresh this page</a>.</p>
      <?php else: ?>
        <p>This order has not been paid yet.</p>
      <?php endif; ?>
      <form method="post" action="<?= e($self) ?>"><input type="hidden" name="action" value="pay">
        <button type="submit" class="shop-button">Pay <?= $money($o['total_minor']) ?> now</button></form>
    </div>
  <?php elseif ($open && $o['provider'] === 'razorpay'): ?>
    <div class="shop-box">
      <?php if ($status === 'pending' && $o['provider_ref'] !== ''): ?>
        <p>Complete your payment to confirm the order.</p>
        <form method="post" action="<?= e($self) ?>" id="shop-rzp-form">
          <input type="hidden" name="action" value="razorpay">
          <input type="hidden" name="razorpay_payment_id" value="">
          <input type="hidden" name="razorpay_order_id" value="">
          <input type="hidden" name="razorpay_signature" value="">
          <button type="button" class="shop-button" id="shop-rzp-pay">Pay <?= $money($o['total_minor']) ?></button>
        </form>
        <script src="<?= e(Razorpay::SCRIPT) ?>"></script>
        <script>
        (function () {
          var opts = <?= json_encode(Razorpay::checkoutOptions($o), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
          var form = document.getElementById('shop-rzp-form');
          opts.handler = function (r) {
            form.elements.razorpay_payment_id.value = r.razorpay_payment_id || '';
            form.elements.razorpay_order_id.value = r.razorpay_order_id || '';
            form.elements.razorpay_signature.value = r.razorpay_signature || '';
            form.submit();
          };
          opts.modal = { ondismiss: function () {} };
          document.getElementById('shop-rzp-pay').addEventListener('click', function () {
            if (typeof Razorpay === 'undefined') { alert('The payment window could not be loaded. Please check your connection and try again.'); return; }
            new Razorpay(opts).open();
          });
        })();
        </script>
      <?php else: ?>
        <p>The payment could not be started.</p>
        <form method="post" action="<?= e($self) ?>"><input type="hidden" name="action" value="pay">
          <button type="submit" class="shop-button">Try again</button></form>
      <?php endif; ?>
    </div>
  <?php elseif ($status === 'cancelled'): ?>
    <p class="shop-box">This order was cancelled. If you think this is a mistake, please contact us.</p>
  <?php elseif ($status === 'refunded'): ?>
    <p class="shop-box">This order was refunded.</p>
  <?php endif; ?>

  <h2 class="shop-subtitle">Order details</h2>
  <div class="shop-table-wrap">
    <table class="shop-table">
      <thead><tr><th scope="col">Product</th><th scope="col" class="shop-num">Price</th><th scope="col" class="shop-num">Qty</th><th scope="col" class="shop-num">Total</th></tr></thead>
      <tbody>
      <?php foreach ($o['items'] as $it): ?>
        <tr>
          <td data-label="Product"><?= e($it['title']) ?><?php if (($it['sku'] ?? '') !== ''): ?> <small class="shop-sku">SKU: <?= e($it['sku']) ?></small><?php endif; ?></td>
          <td data-label="Price" class="shop-num"><?= $money((int)$it['unit_minor']) ?></td>
          <td data-label="Qty" class="shop-num"><?= (int)$it['qty'] ?></td>
          <td data-label="Total" class="shop-num"><?= $money((int)$it['total_minor']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <dl class="shop-totals">
    <dt>Subtotal</dt><dd><?= $money($o['subtotal_minor']) ?></dd>
    <?php if ($o['shipping_minor'] > 0): ?><dt>Shipping</dt><dd><?= $money($o['shipping_minor']) ?></dd><?php endif; ?>
    <dt class="shop-grand">Total</dt><dd class="shop-grand"><?= $money($o['total_minor']) ?></dd>
  </dl>

  <div class="shop-columns">
    <div class="shop-col">
      <h2 class="shop-subtitle">Customer</h2>
      <p><?= e($o['customer_name']) ?><br><?= e($o['customer_email']) ?><?= $o['customer_phone'] !== '' ? '<br>' . e($o['customer_phone']) : '' ?></p>
      <?php if ($b = $addr($o['address']['billing'] ?? null)): ?><h3 class="shop-label">Billing address</h3><p><?= $b ?></p><?php endif; ?>
    </div>
    <div class="shop-col">
      <?php if ($sh = $addr($o['address']['shipping'] ?? null)): ?><h2 class="shop-subtitle">Delivery address</h2><p><?= $sh ?></p><?php endif; ?>
      <?php if (trim((string)$o['notes']) !== ''): ?><h3 class="shop-label">Your notes</h3><p><?= nl2br(e((string)$o['notes'])) ?></p><?php endif; ?>
      <p class="shop-muted">Placed <?= e(local_date($o['created_at'], 'M j, Y H:i')) ?><?= $o['paid_at'] ? ' · paid ' . e(local_date($o['paid_at'], 'M j, Y H:i')) : '' ?></p>
    </div>
  </div>
  <p><a class="shop-link" href="<?= e(Shop::shopUrl()) ?>">Continue shopping</a></p>
</div>
