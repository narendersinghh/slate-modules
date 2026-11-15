<?php
/**
 * Order email (customer or shop). Inline styles only: email clients ignore stylesheets.
 * @var array $order @var string $heading @var string $intro @var string $site @var bool $forShop
 */
use SlateModules\Shop\Orders;
use SlateModules\Shop\Shop;

$o = $order;
$money = static fn(int $m): string => e(Orders::money($o, $m));
$td = 'padding:8px 6px;border-bottom:1px solid #e7e5e4;vertical-align:top;';
$addr = static function (?array $a): string {
    if (!$a) {
        return '';
    }
    $parts = array_filter([$a['name'] ?? '', $a['address_1'] ?? '', $a['address_2'] ?? '', trim(($a['postcode'] ?? '') . ' ' . ($a['city'] ?? '')),
        $a['state'] ?? '', $a['country'] ?? ''], static fn($v) => trim((string)$v) !== '');
    return implode('<br>', array_map('e', $parts));
};
$link = $forShop ? \SlateModules\Shop\Admin::orderUrl((int)$o['id']) : Shop::orderUrl($o['order_key'], true);
if ($forShop && !preg_match('#^https?://#', $link)) {
    $link = site_url(substr($link, strlen(base_path())));
}
?>
<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1c1917;max-width:600px;margin:0 auto">
  <h1 style="font-size:22px;margin:0 0 12px"><?= e($heading) ?></h1>
  <p style="margin:0 0 16px"><?= nl2br(e($intro)) ?></p>
  <p style="margin:0 0 16px"><strong>Order <?= e(Orders::number($o)) ?></strong> · <?= e(Orders::label($o['status'])) ?> · <?= e(local_date($o['created_at'], 'M j, Y')) ?></p>
  <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:0 0 16px">
    <?php foreach ($o['items'] as $it): ?>
      <tr>
        <td style="<?= $td ?>"><?= e($it['title']) ?><?= ($it['sku'] ?? '') !== '' ? ' <span style="color:#78716c">(' . e($it['sku']) . ')</span>' : '' ?> × <?= (int)$it['qty'] ?></td>
        <td style="<?= $td ?>text-align:right;white-space:nowrap"><?= $money((int)$it['total_minor']) ?></td>
      </tr>
    <?php endforeach; ?>
    <tr><td style="<?= $td ?>">Subtotal</td><td style="<?= $td ?>text-align:right"><?= $money($o['subtotal_minor']) ?></td></tr>
    <?php if ($o['shipping_minor'] > 0): ?><tr><td style="<?= $td ?>">Shipping</td><td style="<?= $td ?>text-align:right"><?= $money($o['shipping_minor']) ?></td></tr><?php endif; ?>
    <tr><td style="<?= $td ?>font-weight:bold">Total</td><td style="<?= $td ?>text-align:right;font-weight:bold"><?= $money($o['total_minor']) ?></td></tr>
  </table>
  <p style="margin:0 0 16px"><strong>Customer</strong><br><?= e($o['customer_name']) ?><br><?= e($o['customer_email']) ?><?= $o['customer_phone'] !== '' ? '<br>' . e($o['customer_phone']) : '' ?></p>
  <?php if ($b = $addr($o['address']['billing'] ?? null)): ?><p style="margin:0 0 16px"><strong>Billing address</strong><br><?= $b ?></p><?php endif; ?>
  <?php if ($s = $addr($o['address']['shipping'] ?? null)): ?><p style="margin:0 0 16px"><strong>Delivery address</strong><br><?= $s ?></p><?php endif; ?>
  <?php if (trim((string)$o['notes']) !== ''): ?><p style="margin:0 0 16px"><strong>Notes</strong><br><?= nl2br(e((string)$o['notes'])) ?></p><?php endif; ?>
  <?php if ($forShop): ?><p style="margin:0 0 16px">Payment: <?= e($o['provider']) ?><?= $o['payment_ref'] !== '' ? ' (' . e($o['payment_ref']) . ')' : '' ?></p><?php endif; ?>
  <p style="margin:24px 0"><a href="<?= e($link) ?>" style="display:inline-block;background:#1c1917;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px"><?= $forShop ? 'Open the order' : 'View your order' ?></a></p>
  <p style="margin:0;color:#78716c;font-size:13px"><?= e($site) ?></p>
</div>
