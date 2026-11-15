<?php
/**
 * Orders list (Content → Orders).
 */
use Slate\DB;
use SlateModules\Shop\Admin;
use SlateModules\Shop\Orders;

$counts = Admin::counts();
$status = (string)($_GET['status'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
[$where, $params] = Admin::filterSql(['status' => $status, 'q' => $q]);
$page = max(1, (int)($_GET['pg'] ?? 1));
$per = 50;
try {
    $total = (int)DB::val("SELECT COUNT(*) FROM {{shop_orders}} $where", $params);
    $items = DB::all("SELECT id, order_key, status, customer_name, customer_email, total_minor, currency, provider, meta, created_at, paid_at
        FROM {{shop_orders}} $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
} catch (\Throwable $e) {
    echo '<div class="banner banner-danger">' . icon('alert', 18) . '<div>The orders table is missing. Deactivate and activate the Shop module to create it.</div></div>';
    return;
}
$pill = static fn(string $s): string => match ($s) {
    'paid' => 'pill-success', 'processing' => 'pill-accent', 'completed' => 'pill-soft', 'pending' => 'pill-warn',
    'failed', 'cancelled', 'refunded' => 'pill-danger', default => '',
};
?>
<div class="row-between" style="margin:-8px 0 16px;gap:12px;flex-wrap:wrap">
  <p class="muted" style="margin:0">Orders placed on your website. <strong>Paid</strong> orders are waiting to be processed.</p>
  <a class="btn btn-sm" href="<?= e(admin_url('m/' . Admin::SETTINGS)) ?>"><?= icon('settings', 15) ?> Shop settings</a>
</div>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
  <a class="btn btn-sm<?= $status === '' ? ' btn-primary' : ' btn-ghost' ?>" href="<?= e(Admin::ordersUrl(['q' => $q])) ?>">All <span class="muted"><?= number_format(array_sum($counts)) ?></span></a>
  <?php foreach (Orders::STATUSES as $k => $l): ?>
    <a class="btn btn-sm<?= $status === $k ? ' btn-primary' : ' btn-ghost' ?>" href="<?= e(Admin::ordersUrl(['status' => $k, 'q' => $q])) ?>"><?= e($l) ?> <span class="muted"><?= number_format($counts[$k] ?? 0) ?></span></a>
  <?php endforeach; ?>
</div>
<form class="filter-bar" method="get" action="<?= e(admin_url('m/' . Admin::ORDERS)) ?>">
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <input class="input input-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Order number, name, email, phone or payment id…">
  <button class="btn btn-sm">Search</button>
  <span class="muted small"><?= number_format($total) ?> order<?= $total === 1 ? '' : 's' ?></span>
</form>
<?php if (!$items): ?>
  <div class="card empty-state"><?= icon('shopping', 32) ?><h2>No orders here</h2><p class="muted">Orders appear as soon as customers check out.</p></div>
<?php else: ?>
  <div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Status</th><th>Payment</th><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($items as $o):
        $meta = json_decode((string)$o['meta'], true) ?: [];
        $o['decimals'] = (int)($meta['decimals'] ?? SlateModules\Shop\Money::decimals());
        $url = Admin::ordersUrl(['id' => $o['id']]); ?>
      <tr<?= $o['status'] === 'paid' ? ' style="font-weight:600"' : '' ?>>
        <td class="nowrap"><a href="<?= e($url) ?>">#<?= (int)$o['id'] ?></a></td>
        <td class="nowrap"><?= e(local_date($o['created_at'], 'M j, Y H:i')) ?></td>
        <td><?= e($o['customer_name']) ?><br><small class="muted"><?= e($o['customer_email']) ?></small></td>
        <td><span class="pill <?= $pill($o['status']) ?>"><?= e(Orders::label($o['status'])) ?></span></td>
        <td><?= e(ucfirst($o['provider'])) ?></td>
        <td class="num nowrap"><?= e(Orders::money($o, (int)$o['total_minor'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
  <?php $pages = (int)ceil($total / $per); if ($pages > 1): ?>
    <div class="row-between" style="margin-top:12px">
      <span class="muted small">Page <?= $page ?> of <?= $pages ?></span>
      <span>
        <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e(Admin::ordersUrl(['status' => $status, 'q' => $q, 'pg' => $page - 1])) ?>">Previous</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e(Admin::ordersUrl(['status' => $status, 'q' => $q, 'pg' => $page + 1])) ?>">Next</a><?php endif; ?>
      </span>
    </div>
  <?php endif; ?>
<?php endif; ?>
