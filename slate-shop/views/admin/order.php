<?php
/**
 * One order (Content → Orders → #id).
 */
use SlateModules\Shop\Admin;
use SlateModules\Shop\Orders;
use SlateModules\Shop\Shop;

$o = Orders::find((int)($_GET['id'] ?? 0));
if (!$o): ?>
  <div class="card empty-state"><?= icon('shopping', 32) ?><h2>Order not found</h2><p><a href="<?= e(Admin::ordersUrl()) ?>">Back to all orders</a></p></div>
<?php return; endif;
$money = static fn(int $m): string => e(Orders::money($o, $m));
$addr = static function (?array $a): string {
    if (!$a) {
        return '';
    }
    $parts = array_filter([$a['name'] ?? '', $a['address_1'] ?? '', $a['address_2'] ?? '', trim(($a['postcode'] ?? '') . ' ' . ($a['city'] ?? '')),
        $a['state'] ?? '', $a['country'] ?? ''], static fn($v) => trim((string)$v) !== '');
    return implode('<br>', array_map('e', $parts));
};
$action = admin_url('m/' . Admin::ORDERS . '/action');
$providerRefLink = '';
if ($o['provider'] === 'stripe' && str_starts_with((string)$o['payment_ref'], 'pi_')) {
    $providerRefLink = 'https://dashboard.stripe.com/' . (($o['meta']['stripe_mode'] ?? 'test') === 'test' ? 'test/' : '') . 'payments/' . rawurlencode((string)$o['payment_ref']);
} elseif ($o['provider'] === 'razorpay' && str_starts_with((string)$o['payment_ref'], 'pay_')) {
    $providerRefLink = 'https://dashboard.razorpay.com/app/payments/' . rawurlencode((string)$o['payment_ref']);
}
?>
<div class="row-between" style="margin-bottom:16px">
  <a class="btn btn-ghost btn-sm" href="<?= e(Admin::ordersUrl()) ?>"><?= icon('chevron-left', 15) ?> All orders</a>
  <a class="btn btn-sm" href="<?= e(Shop::orderUrl($o['order_key'])) ?>" target="_blank" rel="noopener"><?= icon('external', 15) ?> Customer’s order page</a>
</div>
<div class="split split-wide">
  <div class="stack">
    <section class="card">
      <div class="card-head"><h2>Order <?= e(Orders::number($o)) ?></h2><span class="pill<?= $o['status'] === 'paid' ? ' pill-success' : ($o['status'] === 'pending' ? ' pill-warn' : '') ?>"><?= e(Orders::label($o['status'])) ?></span></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Product</th><th>SKU</th><th class="num">Price</th><th class="num">Qty</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach ($o['items'] as $it): ?>
          <tr>
            <td><?php if (\Slate\Posts::find((int)$it['product_id'])): ?><a href="<?= e(admin_url('content/product/' . (int)$it['product_id'])) ?>"><?= e($it['title']) ?></a><?php else: ?><?= e($it['title']) ?><?php endif; ?></td>
            <td><?= e($it['sku'] ?? '') ?></td>
            <td class="num"><?= $money((int)$it['unit_minor']) ?></td>
            <td class="num"><?= (int)$it['qty'] ?></td>
            <td class="num"><?= $money((int)$it['total_minor']) ?></td>
          </tr>
        <?php endforeach; ?>
          <tr><td colspan="4">Subtotal</td><td class="num"><?= $money($o['subtotal_minor']) ?></td></tr>
          <tr><td colspan="4">Shipping</td><td class="num"><?= $money($o['shipping_minor']) ?></td></tr>
          <tr><td colspan="4"><strong>Total</strong></td><td class="num"><strong><?= $money($o['total_minor']) ?></strong> <span class="muted"><?= e($o['currency']) ?></span></td></tr>
        </tbody>
      </table></div>
    </section>

    <section class="card"><div class="card-pad">
      <div class="grid-2">
        <div>
          <strong class="small-label">Customer</strong>
          <p><?= e($o['customer_name']) ?><br><a href="mailto:<?= e($o['customer_email']) ?>"><?= e($o['customer_email']) ?></a><?= $o['customer_phone'] !== '' ? '<br><a href="tel:' . e(preg_replace('/[^0-9+]/', '', $o['customer_phone'])) . '">' . e($o['customer_phone']) . '</a>' : '' ?></p>
          <?php if ($b = $addr($o['address']['billing'] ?? null)): ?><strong class="small-label">Billing address</strong><p><?= $b ?></p><?php endif; ?>
        </div>
        <div>
          <strong class="small-label">Delivery address</strong>
          <p><?= $addr($o['address']['shipping'] ?? null) ?: '<span class="muted">Same as billing</span>' ?></p>
          <?php if (trim((string)$o['notes']) !== ''): ?><strong class="small-label">Customer notes</strong><p><?= nl2br(e((string)$o['notes'])) ?></p><?php endif; ?>
        </div>
      </div>
    </div></section>

    <section class="card">
      <div class="card-head"><h2>History</h2></div>
      <div class="card-pad">
        <?php if (!$o['history']): ?><p class="muted">Nothing yet.</p><?php else: ?>
        <ul class="list list-compact">
          <?php foreach (array_reverse($o['history']) as $h): ?>
            <li><span class="muted small nowrap"><?= e(local_date((string)($h['at'] ?? ''), 'M j, Y H:i')) ?></span> — <?= e((string)($h['msg'] ?? '')) ?></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <aside class="stack">
    <form method="post" action="<?= e($action) ?>" class="card"><div class="card-pad stack-sm">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="do" value="status">
      <label class="small-label" for="shop-status">Status</label>
      <select class="input" id="shop-status" name="status">
        <?php foreach (Orders::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $o['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
      <label class="choice"><input type="checkbox" name="notify" value="1"> Email the customer about the change</label>
      <?php if (in_array($o['status'], ['pending', 'failed'], true)): ?><p class="help small">Choosing “Paid”, “Processing” or “Completed” records the payment: items are taken out of stock and the payment confirmation emails are sent.</p><?php endif; ?>
      <button class="btn btn-primary">Update status</button>
    </div></form>

    <section class="card"><div class="card-pad stack-sm">
      <div><strong class="small-label">Placed</strong><div><?= e(local_date($o['created_at'], 'M j, Y H:i')) ?></div></div>
      <div><strong class="small-label">Payment method</strong><div><?= e(ucfirst($o['provider'])) ?><?= isset($o['meta']['stripe_mode']) ? ' (' . e($o['meta']['stripe_mode']) . ')' : (isset($o['meta']['razorpay_mode']) ? ' (' . e($o['meta']['razorpay_mode']) . ')' : '') ?></div></div>
      <?php if ($o['paid_at']): ?><div><strong class="small-label">Paid</strong><div><?= e(local_date($o['paid_at'], 'M j, Y H:i')) ?></div></div><?php endif; ?>
      <?php if ($o['provider_ref'] !== ''): ?><div><strong class="small-label"><?= $o['provider'] === 'stripe' ? 'Checkout session' : 'Provider order' ?></strong><div class="small" style="word-break:break-all"><?= e($o['provider_ref']) ?></div></div><?php endif; ?>
      <?php if ($o['payment_ref'] !== ''): ?><div><strong class="small-label">Payment reference</strong><div class="small" style="word-break:break-all">
        <?= $providerRefLink !== '' ? '<a href="' . e($providerRefLink) . '" target="_blank" rel="noopener noreferrer">' . e($o['payment_ref']) . '</a>' : e($o['payment_ref']) ?></div></div><?php endif; ?>
    </div></section>

    <form method="post" action="<?= e($action) ?>" class="card"><div class="card-pad stack-sm">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="do" value="notes">
      <label class="small-label" for="shop-notes">Private notes</label>
      <textarea class="input" id="shop-notes" name="admin_notes" rows="4" placeholder="Only visible to your team"><?= e((string)$o['admin_notes']) ?></textarea>
      <button class="btn btn-sm">Save notes</button>
    </div></form>

    <form method="post" action="<?= e($action) ?>" class="card"><div class="card-pad">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="do" value="resend">
      <button class="btn btn-sm btn-ghost" data-confirm="Send the order email to the customer and the shop again?"><?= icon('message', 15) ?> Resend order email</button>
    </div></form>
  </aside>
</div>
