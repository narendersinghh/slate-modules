<?php
/**
 * One order (Orders → #id). $o: the decoded order or null.
 */
use Slate\AdminUi;
use SlateModules\Shop\Admin;
use SlateModules\Shop\Orders;
use SlateModules\Shop\Shop;

if (!$o) {
    echo AdminUi::emptyState('Order not found', 'It may have been removed, or the link is incomplete.', 'shopping', ['Back to orders' => Admin::ordersUrl()]);
    return;
}
$money = static fn(int $m): string => e(Orders::money($o, $m));
$addr = static function (?array $a): string {
    if (!$a) {
        return '';
    }
    $parts = array_filter([$a['name'] ?? '', $a['address_1'] ?? '', $a['address_2'] ?? '', trim(($a['postcode'] ?? '') . ' ' . ($a['city'] ?? '')),
        $a['state'] ?? '', $a['country'] ?? ''], static fn($v) => trim((string)$v) !== '');
    return implode('<br>', array_map('e', $parts));
};
$action = Admin::orderUrl($o['id']);
$mode = $o['meta']['stripe_mode'] ?? $o['meta']['razorpay_mode'] ?? null;
$refLink = '';
if ($o['provider'] === 'stripe' && str_starts_with((string)$o['payment_ref'], 'pi_')) {
    $refLink = 'https://dashboard.stripe.com/' . (($o['meta']['stripe_mode'] ?? 'test') === 'test' ? 'test/' : '') . 'payments/' . rawurlencode((string)$o['payment_ref']);
} elseif ($o['provider'] === 'razorpay' && str_starts_with((string)$o['payment_ref'], 'pay_')) {
    $refLink = 'https://dashboard.razorpay.com/app/payments/' . rawurlencode((string)$o['payment_ref']);
}
$unpaid = in_array($o['status'], ['pending', 'failed'], true);
$billing = $addr($o['address']['billing'] ?? null);
$shipping = $addr($o['address']['shipping'] ?? null);

echo AdminUi::header('Order ' . Orders::number($o), 'Placed ' . local_date($o['created_at'], 'M j, Y \a\t H:i') . ' by ' . ($o['customer_name'] ?: $o['customer_email']), 'shopping', [
    'All orders' => [Admin::ordersUrl(), 'ghost', 'chevron-left'],
    'Customer’s order page' => [Shop::orderUrl($o['order_key']), '', 'external'],
]);
?>
<div class="split split-wide">
  <div class="stack">
    <section class="card">
      <div class="card-head"><h2>Items</h2><?= AdminUi::pill(Orders::label($o['status']), Admin::tone($o['status'])) ?></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Product</th><th>SKU</th><th class="num">Price</th><th class="num">Qty</th><th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach ($o['items'] as $it): ?>
          <tr>
            <td><?php if (\Slate\Posts::find((int)$it['product_id'])): ?><a href="<?= e(admin_url('content/product/' . (int)$it['product_id'])) ?>"><?= e($it['title']) ?></a><?php else: ?><?= e($it['title']) ?><?php endif; ?></td>
            <td class="muted"><?= e($it['sku'] ?? '') ?></td>
            <td class="num"><?= $money((int)$it['unit_minor']) ?></td>
            <td class="num"><?= (int)$it['qty'] ?></td>
            <td class="num"><?= $money((int)$it['total_minor']) ?></td>
          </tr>
        <?php endforeach; ?>
          <tr><td colspan="4" class="muted">Subtotal</td><td class="num"><?= $money($o['subtotal_minor']) ?></td></tr>
          <tr><td colspan="4" class="muted">Shipping</td><td class="num"><?= $money($o['shipping_minor']) ?></td></tr>
          <tr><td colspan="4"><strong>Total</strong></td><td class="num nowrap"><strong><?= $money($o['total_minor']) ?></strong> <span class="muted"><?= e($o['currency']) ?></span></td></tr>
        </tbody>
      </table></div>
    </section>

    <section class="card">
      <div class="card-head"><h2>Customer</h2></div>
      <div class="card-pad grid-2">
        <div class="stack-sm">
          <div><?= e($o['customer_name']) ?><br><a href="mailto:<?= e($o['customer_email']) ?>"><?= e($o['customer_email']) ?></a><?= $o['customer_phone'] !== '' ? '<br><a href="tel:' . e(preg_replace('/[^0-9+]/', '', $o['customer_phone'])) . '">' . e($o['customer_phone']) . '</a>' : '' ?></div>
          <?php if ($billing !== ''): ?><div><strong class="small-label">Billing address</strong><div><?= $billing ?></div></div><?php endif; ?>
        </div>
        <div class="stack-sm">
          <div><strong class="small-label">Delivery address</strong><div><?= $shipping !== '' ? $shipping : '<span class="muted">' . ($billing !== '' ? 'Same as billing' : 'None given') . '</span>' ?></div></div>
          <?php if (trim((string)$o['notes']) !== ''): ?><div><strong class="small-label">Customer notes</strong><div><?= nl2br(e((string)$o['notes'])) ?></div></div><?php endif; ?>
        </div>
      </div>
    </section>

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
    <form method="post" action="<?= e($action) ?>" class="card">
      <div class="card-head"><h2>Status</h2></div>
      <div class="card-pad stack-sm">
        <?= csrf_field() ?><input type="hidden" name="do" value="status">
        <select class="input" id="shop-status" name="status" aria-label="Status">
          <?php foreach (Orders::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= $o['status'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <label class="choice"><input type="checkbox" name="notify" value="1"> Email the customer about the change</label>
        <?php if ($unpaid): ?><p class="muted small">Choosing Paid, Processing or Completed records the payment: items are taken out of stock and confirmation emails are sent.</p><?php endif; ?>
        <button class="btn btn-primary">Update status</button>
      </div>
    </form>

    <section class="card">
      <div class="card-head"><h2>Payment</h2></div>
      <div class="card-pad kv">
        <span>Method</span><span><?= e(Admin::PROVIDERS[$o['provider']] ?? ucfirst($o['provider'])) ?><?= $mode ? ' <span class="muted">(' . e($mode) . ')</span>' : '' ?></span>
        <span>Paid</span><span><?= $o['paid_at'] ? e(local_date($o['paid_at'], 'M j, Y H:i')) : '<span class="muted">Not yet</span>' ?></span>
        <?php if ($o['provider_ref'] !== ''): ?><span><?= $o['provider'] === 'stripe' ? 'Checkout session' : 'Provider order' ?></span><code><?= e($o['provider_ref']) ?></code><?php endif; ?>
        <?php if ($o['payment_ref'] !== ''): ?><span>Reference</span><code><?= $refLink !== '' ? '<a href="' . e($refLink) . '" target="_blank" rel="noopener noreferrer">' . e($o['payment_ref']) . '</a>' : e($o['payment_ref']) ?></code><?php endif; ?>
      </div>
    </section>

    <form method="post" action="<?= e($action) ?>" class="card">
      <div class="card-head"><h2>Private notes</h2></div>
      <div class="card-pad stack-sm">
        <?= csrf_field() ?><input type="hidden" name="do" value="notes">
        <textarea class="input" name="admin_notes" rows="4" placeholder="Only visible to your team" aria-label="Private notes"><?= e((string)$o['admin_notes']) ?></textarea>
        <button class="btn btn-sm">Save notes</button>
      </div>
    </form>

    <form method="post" action="<?= e($action) ?>" class="card"><div class="card-pad">
      <?= csrf_field() ?><input type="hidden" name="do" value="resend">
      <button class="btn btn-sm btn-ghost" data-confirm="Send the order email to the customer and the shop again?"><?= icon('message', 15) ?> Resend order email</button>
    </div></form>
  </aside>
</div>
