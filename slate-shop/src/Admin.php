<?php
declare(strict_types=1);

namespace SlateModules\Shop;

use Slate\AdminUi;
use Slate\Auth;
use Slate\DB;
use Slate\ListTable;
use Slate\Session;

/** Dashboard: the Orders list and the order detail page (POSTs are CSRF-checked by the dashboard). */
final class Admin
{
    public const MODULE = 'slate-shop';
    public const ORDERS = 'shop-orders';
    public const ORDER = 'shop-order';
    public const CAP = 'edit_others_posts';

    public const PROVIDERS = ['manual' => 'Manual', 'stripe' => 'Stripe', 'razorpay' => 'Razorpay'];
    private const DATES = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month', 'year' => 'This year'];
    private const SORTS = ['id' => 'id', 'date' => 'created_at', 'total' => 'total_minor'];
    private const PAID = ['paid', 'processing', 'completed'];

    /** Bulk action => [target status, statuses it applies to]. */
    private const BULK = [
        'mark_paid' => ['paid', ['pending', 'failed']],
        'processing' => ['processing', ['pending', 'failed', 'paid']],
        'completed' => ['completed', ['pending', 'failed', 'paid', 'processing']],
        'cancel' => ['cancelled', ['pending', 'failed', 'paid', 'processing']],
    ];

    public static function ordersUrl(): string
    {
        return admin_url('m/' . self::ORDERS);
    }

    public static function orderUrl(int $id): string
    {
        return admin_url('m/' . self::ORDER) . '?id=' . $id;
    }

    public static function tone(string $status): string
    {
        return match ($status) {
            'paid' => 'success', 'processing' => 'info', 'pending' => 'warn', 'failed', 'cancelled', 'refunded' => 'danger', default => '',
        };
    }

    private static function who(): string
    {
        return (string)(Auth::user()['display_name'] ?? '') ?: 'admin';
    }

    // ------------------------------------------------------------------ orders list

    public static function ordersPage(): void
    {
        if (isset($_GET['id'])) {
            redirect(self::orderUrl((int)$_GET['id'])); // links in older emails / webhooks
        }
        try {
            $counts = self::counts();
        } catch (\Throwable) {
            echo AdminUi::emptyState('The orders table is missing', 'Deactivate and activate the Shop module to create it.', 'alert');
            return;
        }
        $t = self::table($counts);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            self::bulk($t);
        }
        $s = $t->state();
        [$where, $params] = self::where($s);
        $order = self::SORTS[$s['sort']] . ' ' . ($s['dir'] === 'asc' ? 'ASC' : 'DESC') . ($s['sort'] === 'id' ? '' : ', id DESC');
        $cols = 'id, order_key, status, customer_name, customer_email, customer_phone, items, total_minor, currency, provider, payment_ref, meta, created_at, paid_at';
        if ($t->exporting()) {
            $t->exportCsv(array_map([self::class, 'row'], DB::all("SELECT $cols FROM {{shop_orders}} $where ORDER BY $order LIMIT 50000", $params)));
        }
        $total = (int)DB::val("SELECT COUNT(*) FROM {{shop_orders}} $where", $params);
        $rows = array_map([self::class, 'row'], DB::all("SELECT $cols FROM {{shop_orders}} $where ORDER BY $order LIMIT " . (int)$s['per'] . ' OFFSET ' . (int)$s['offset'], $params));

        echo AdminUi::header('Orders', 'Orders placed in your shop. Paid orders are waiting for you to process them.', 'shopping', [
            'Products' => [admin_url('content/' . Product::TYPE), 'ghost', 'shopping'],
            'Settings' => [AdminUi::settingsUrl(self::MODULE), 'ghost', 'settings'],
        ]);
        if (!Shop::providers()) {
            echo '<div class="banner banner-danger">' . icon('alert', 18) . '<div>No payment method is available, so customers cannot check out. <a href="'
                . e(AdminUi::settingsUrl(self::MODULE)) . '">Turn one on in the settings</a>.</div></div>';
        }
        if (array_sum($counts)) {
            echo AdminUi::kpis(self::kpis($counts));
        }
        echo $t->render($rows, $total);
    }

    private static function table(array $counts): ListTable
    {
        $views = ['all' => ['All', array_sum($counts)]];
        foreach (Orders::STATUSES as $k => $label) {
            if (($counts[$k] ?? 0) > 0 || in_array($k, ['pending', 'paid', 'processing', 'completed'], true)) {
                $views[$k] = [$label, $counts[$k] ?? 0];
            }
        }
        $bulk = [
            'mark_paid' => ['label' => 'Mark paid', 'confirm' => 'Record payment for the selected unpaid orders? Stock is updated and confirmation emails are sent.'],
            'processing' => 'Mark processing',
            'completed' => 'Mark completed',
            'cancel' => ['label' => 'Cancel', 'danger' => true, 'confirm' => 'Cancel the selected orders? Customers are not emailed and payments are not refunded automatically.'],
        ];
        return new ListTable('shop.orders', [
            'number' => ['label' => 'Order', 'primary' => true, 'sort' => 'id', 'sort_dir' => 'desc',
                'render' => static fn(array $o): string => '<a class="row-title" href="' . e(self::orderUrl($o['id'])) . '">' . e(Orders::number($o)) . '</a>',
                'export' => static fn(array $o): string => Orders::number($o)],
            'date' => ['label' => 'Date', 'sort' => 'date', 'sort_dir' => 'desc', 'class' => 'nowrap',
                'render' => static fn(array $o): string => e(local_date($o['created_at'], 'M j, Y H:i')),
                'export' => static fn(array $o): string => local_date($o['created_at'], 'Y-m-d H:i')],
            'customer' => ['label' => 'Customer',
                'render' => static fn(array $o): string => e($o['customer_name']) . '<br><small class="muted">' . e($o['customer_email']) . '</small>',
                'export' => static fn(array $o): string => trim($o['customer_name'] . ($o['customer_email'] !== '' ? ' <' . $o['customer_email'] . '>' : ''))],
            'email' => ['label' => 'Email', 'default' => false, 'render' => static fn(array $o): string => e($o['customer_email'])],
            'phone' => ['label' => 'Phone', 'default' => false, 'render' => static fn(array $o): string => e($o['customer_phone'])],
            'items' => ['label' => 'Items', 'render' => static fn(array $o): string => e(self::itemsSummary($o['items']))],
            'status' => ['label' => 'Status', 'render' => static fn(array $o): string => AdminUi::pill(Orders::label($o['status']), self::tone($o['status'])),
                'export' => static fn(array $o): string => Orders::label($o['status'])],
            'payment' => ['label' => 'Payment', 'render' => static fn(array $o): string => e(self::PROVIDERS[$o['provider']] ?? ucfirst($o['provider']))],
            'payment_ref' => ['label' => 'Payment reference', 'default' => false, 'render' => static fn(array $o): string => '<code>' . e($o['payment_ref']) . '</code>',
                'export' => static fn(array $o): string => $o['payment_ref']],
            'paid' => ['label' => 'Paid on', 'default' => false, 'class' => 'nowrap', 'render' => static fn(array $o): string => e(local_date($o['paid_at'], 'M j, Y H:i')),
                'export' => static fn(array $o): string => local_date($o['paid_at'], 'Y-m-d H:i')],
            'total' => ['label' => 'Total', 'sort' => 'total', 'sort_dir' => 'desc', 'align' => 'right', 'class' => 'nowrap',
                'render' => static fn(array $o): string => e(Orders::money($o, $o['total_minor'])),
                'export' => static fn(array $o): string => Money::toDecimal($o['total_minor'], $o['decimals'])],
            'currency' => ['label' => 'Currency', 'default' => false],
        ], [
            'base' => self::ordersUrl(),
            'search' => 'Search by order number, name, email or phone…',
            'views' => $views,
            'filters' => [
                'method' => ['label' => 'Payment method', 'all' => 'All payment methods', 'options' => self::PROVIDERS],
                'date' => ['label' => 'Date', 'all' => 'Any date', 'options' => self::DATES],
            ],
            'bulk' => $bulk, 'bulk_action' => self::ordersUrl(), 'all_matching' => true,
            'sort' => 'id', 'dir' => 'desc', 'export' => true,
            'row_actions' => static fn(array $o): string => '<a href="' . e(self::orderUrl($o['id'])) . '">View</a>'
                . '<a href="' . e(Shop::orderUrl($o['order_key'])) . '" target="_blank" rel="noopener">Customer page</a>',
            'empty' => ['icon' => 'shopping', 'title' => 'No orders yet', 'text' => 'Orders appear here as soon as someone checks out.',
                'html' => '<a class="btn" href="' . e(admin_url('content/' . Product::TYPE)) . '">' . icon('shopping', 16) . ' Manage products</a>'],
        ]);
    }

    /** Decode a list row: ints, items, decimals. */
    private static function row(array $o): array
    {
        $o['id'] = (int)$o['id'];
        $o['total_minor'] = (int)$o['total_minor'];
        $o['items'] = json_decode((string)$o['items'], true) ?: [];
        $meta = json_decode((string)$o['meta'], true) ?: [];
        $o['decimals'] = (int)($meta['decimals'] ?? Money::decimals());
        return $o;
    }

    private static function itemsSummary(array $items): string
    {
        if (!$items) {
            return '';
        }
        $first = $items[0];
        $out = (string)($first['title'] ?? '') . ((int)($first['qty'] ?? 1) > 1 ? ' × ' . (int)$first['qty'] : '');
        return $out . (count($items) > 1 ? ' + ' . (count($items) - 1) . ' more' : '');
    }

    /** WHERE clause for the table state (view, filters, search); only whitelisted values reach the SQL. */
    private static function where(array $s): array
    {
        $w = [];
        $p = [];
        if (isset(Orders::STATUSES[$s['view']])) {
            $w[] = 'status = :status';
            $p['status'] = $s['view'];
        }
        $method = $s['filters']['method'] ?? '';
        if (isset(self::PROVIDERS[$method])) {
            $w[] = 'provider = :provider';
            $p['provider'] = $method;
        }
        if (($from = self::since($s['filters']['date'] ?? '')) !== null) {
            $w[] = 'created_at >= :since';
            $p['since'] = $from;
        }
        $search = trim($s['q']);
        if ($search !== '') {
            $num = ltrim($search, '#');
            if (ctype_digit($num)) {
                $w[] = 'id = :oid';
                $p['oid'] = (int)$num;
            } else {
                // "!" as the LIKE escape character works the same on MySQL and SQLite.
                $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($search, 0, 100)) . '%';
                $w[] = "(customer_name LIKE :q1 ESCAPE '!' OR customer_email LIKE :q2 ESCAPE '!' OR customer_phone LIKE :q3 ESCAPE '!'"
                    . " OR provider_ref LIKE :q4 ESCAPE '!' OR payment_ref LIKE :q5 ESCAPE '!')";
                $p += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
            }
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }

    /** Start of a date filter range, as a UTC timestamp string (ranges follow the site's time zone). */
    private static function since(string $range): ?string
    {
        if (!isset(self::DATES[$range])) {
            return null;
        }
        try {
            $tz = new \DateTimeZone((string)option('timezone', 'UTC'));
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }
        $d = new \DateTimeImmutable('now', $tz);
        $d = match ($range) {
            'today' => $d->setTime(0, 0),
            '7d' => $d->modify('-7 days'),
            '30d' => $d->modify('-30 days'),
            'month' => $d->modify('first day of this month')->setTime(0, 0),
            'year' => $d->setDate((int)$d->format('Y'), 1, 1)->setTime(0, 0),
        };
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Orders per status. Throws when the table does not exist. */
    private static function counts(): array
    {
        return array_map('intval', array_column(DB::all('SELECT status, COUNT(*) AS c FROM {{shop_orders}} GROUP BY status'), 'c', 'status'));
    }

    private static function kpis(array $counts): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $currency = Money::currency();
        $revenue = (int)DB::val("SELECT COALESCE(SUM(total_minor), 0) FROM {{shop_orders}} WHERE status IN ('paid', 'processing', 'completed')
            AND paid_at >= :since AND currency = :cur", ['since' => $since, 'cur' => $currency]);
        $recent = (int)DB::val("SELECT COUNT(*) FROM {{shop_orders}} WHERE created_at >= :since AND status <> 'failed'", ['since' => $since]);
        return [
            'Revenue · 30 days' => Money::format($revenue, Money::decimals(), $currency),
            'Orders · 30 days' => number_format($recent),
            'Awaiting payment' => number_format($counts['pending'] ?? 0),
            'To process' => number_format($counts['paid'] ?? 0),
        ];
    }

    /** Bulk status change from the list (selected rows, or every row matching the current search and filters). */
    private static function bulk(ListTable $t): never
    {
        $action = (string)($_POST['action'] ?? '');
        if (!isset(self::BULK[$action])) {
            Session::flash('error', 'Choose an action.');
            back();
        }
        if (!empty($_POST['all_matching'])) {
            parse_str((string)($_POST['query'] ?? ''), $get);
            $saved = $_GET;
            $_GET = $get;
            $state = $t->state(); // the list's search, view and filters at the time of the click
            $_GET = $saved;
            [$where, $params] = self::where($state);
            $ids = array_map('intval', DB::col("SELECT id FROM {{shop_orders}} $where ORDER BY id LIMIT 5000", $params));
        } else {
            $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        }
        [$status, $from] = self::BULK[$action];
        $who = self::who();
        $done = 0;
        $skipped = 0;
        foreach (array_unique($ids) as $id) {
            $o = Orders::find($id);
            if (!$o || !in_array($o['status'], $from, true) || !Orders::setStatus($id, $status, false, $who)) {
                $skipped++;
                continue;
            }
            $done++;
        }
        if ($done) {
            log_activity('shop_order_status', number_format($done) . ' orders → ' . $status);
        }
        $msg = number_format($done) . ' order' . ($done === 1 ? '' : 's') . ' marked ' . strtolower(Orders::label($status)) . '.';
        if ($skipped) {
            $msg .= ' ' . number_format($skipped) . ' skipped (already ' . strtolower(Orders::label($status)) . ' or not in a status this action applies to).';
        }
        Session::flash($done ? 'success' : 'error', $msg);
        back();
    }

    // ------------------------------------------------------------------ one order

    public static function orderPage(): void
    {
        $order = Orders::find((int)($_GET['id'] ?? 0));
        if (!$order) {
            http_response_code(404);
        } elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            self::orderAction($order);
        }
        echo Shop::view('admin/order', ['o' => $order]);
    }

    private static function orderAction(array $order): never
    {
        $id = $order['id'];
        $do = (string)($_POST['do'] ?? '');
        $who = self::who();
        if ($do === 'status') {
            $status = (string)($_POST['status'] ?? '');
            $notify = !empty($_POST['notify']);
            if (!isset(Orders::STATUSES[$status])) {
                Session::flash('error', 'Choose a valid status.');
            } elseif ($status === $order['status']) {
                Session::flash('success', 'The order already has that status.');
            } elseif (Orders::setStatus($id, $status, $notify, $who)) {
                if (in_array($status, self::PAID, true) && in_array($order['status'], ['pending', 'failed'], true)) {
                    Session::flash('success', 'Payment recorded: stock was updated and confirmation emails were sent. Status: ' . Orders::label($status) . '.');
                } else {
                    Session::flash('success', 'Status changed to ' . Orders::label($status) . ($notify ? ' and the customer was emailed.' : '.'));
                }
                log_activity('shop_order_status', Orders::number($order) . ' → ' . $status);
            } else {
                Session::flash('error', 'The status could not be changed (it may have changed meanwhile). Please reload.');
            }
        } elseif ($do === 'notes') {
            Orders::saveAdminNotes($id, trim(str_replace("\r\n", "\n", (string)($_POST['admin_notes'] ?? ''))));
            Session::flash('success', 'Notes saved.');
        } elseif ($do === 'resend') {
            Orders::sendEmails($order, in_array($order['status'], self::PAID, true) ? 'paid' : 'received');
            Orders::log($id, 'Confirmation email resent by ' . $who . '.');
            Session::flash('success', 'Email sent again.');
        }
        redirect(self::orderUrl($id));
    }
}
