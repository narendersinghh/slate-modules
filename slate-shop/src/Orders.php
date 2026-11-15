<?php
declare(strict_types=1);

namespace SlateModules\Shop;

use Slate\DB;
use Slate\Mailer;
use Slate\Options;
use Slate\Webhooks;

/** Orders: creation, idempotent payment confirmation, status changes, emails and webhooks. */
final class Orders
{
    public const STATUSES = [
        'pending' => 'Pending payment', 'paid' => 'Paid', 'processing' => 'Processing', 'completed' => 'Completed',
        'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'failed' => 'Failed',
    ];

    /** Unpaid online-payment orders are cancelled after this many hours. */
    public const UNPAID_HOURS = 48;

    // ------------------------------------------------------------------ reading

    public static function find(int $id): ?array
    {
        return self::decode(DB::row('SELECT * FROM {{shop_orders}} WHERE id = :id', ['id' => $id]));
    }

    public static function findByKey(string $key): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/', $key)) {
            return null;
        }
        return self::decode(DB::row('SELECT * FROM {{shop_orders}} WHERE order_key = :k', ['k' => $key]));
    }

    public static function findByRef(string $provider, string $ref): ?array
    {
        if ($ref === '') {
            return null;
        }
        return self::decode(DB::row('SELECT * FROM {{shop_orders}} WHERE provider = :p AND provider_ref = :r', ['p' => $provider, 'r' => $ref]));
    }

    private static function decode(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        foreach (['subtotal_minor', 'shipping_minor', 'total_minor'] as $k) {
            $row[$k] = (int)$row[$k];
        }
        foreach (['address', 'items', 'meta', 'history'] as $k) {
            $v = json_decode((string)($row[$k] ?? ''), true);
            $row[$k] = is_array($v) ? $v : [];
        }
        $row['decimals'] = (int)($row['meta']['decimals'] ?? Money::decimals());
        return $row;
    }

    /** Display formatting in the order's own currency and decimals. */
    public static function money(array $order, int $minor): string
    {
        return Money::format($minor, $order['decimals'], (string)$order['currency']);
    }

    public static function number(array $order): string
    {
        return '#' . $order['id'];
    }

    public static function label(string $status): string
    {
        return self::STATUSES[$status] ?? ucfirst($status);
    }

    // ------------------------------------------------------------------ writing

    /**
     * Create a pending order from validated checkout data and cart lines (all amounts recomputed server-side).
     * @param array{name:string, email:string, phone:string, notes:string, billing:array, shipping:?array} $customer
     */
    public static function create(array $customer, array $lines, array $totals, string $provider): array
    {
        $items = [];
        foreach ($lines as $l) {
            $items[] = ['product_id' => (int)$l['id'], 'title' => (string)$l['title'], 'sku' => (string)$l['sku'],
                'unit_minor' => (int)$l['unit'], 'qty' => (int)$l['qty'], 'total_minor' => (int)$l['total']];
        }
        $now = now();
        $key = bin2hex(random_bytes(20));
        $id = DB::insert('shop_orders', [
            'order_key' => $key,
            'status' => 'pending',
            'customer_name' => mb_substr($customer['name'], 0, 191),
            'customer_email' => mb_substr($customer['email'], 0, 191),
            'customer_phone' => mb_substr($customer['phone'], 0, 60),
            'address' => self::json(['billing' => $customer['billing'], 'shipping' => $customer['shipping']]),
            'items' => self::json($items),
            'subtotal_minor' => $totals['subtotal'],
            'shipping_minor' => $totals['shipping'],
            'total_minor' => $totals['total'],
            'currency' => Money::currency(),
            'provider' => $provider,
            'provider_ref' => '',
            'payment_ref' => '',
            'meta' => self::json(['decimals' => Money::decimals(), 'ip' => self::ipHash()]),
            'notes' => mb_substr($customer['notes'], 0, 2000),
            'admin_notes' => '',
            'history' => self::json([['at' => $now, 'msg' => 'Order placed (' . $provider . ').']]),
            'created_at' => $now,
            'updated_at' => $now,
            'paid_at' => null,
        ]);
        $order = self::find($id);
        self::dispatch('shop.order_created', $order);
        return $order;
    }

    private static function ipHash(): string
    {
        // Enough to spot abuse without storing the address itself.
        return substr(hash('sha256', \Slate\Auth::ip() . '|' . date('Y-m')), 0, 16);
    }

    public static function json(mixed $v): string
    {
        return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Append a line to the order history. */
    public static function log(int $id, string $msg): void
    {
        $o = self::find($id);
        if (!$o) {
            return;
        }
        $h = $o['history'];
        $h[] = ['at' => now(), 'msg' => mb_substr($msg, 0, 500)];
        DB::update('shop_orders', ['history' => self::json(array_slice($h, -100)), 'updated_at' => now()], ['id' => $id]);
    }

    public static function setProviderRef(int $id, string $provider, string $ref, array $meta = []): void
    {
        $o = self::find($id);
        if (!$o) {
            return;
        }
        DB::update('shop_orders', ['provider' => $provider, 'provider_ref' => mb_substr($ref, 0, 191),
            'meta' => self::json(array_merge($o['meta'], $meta)), 'updated_at' => now()], ['id' => $id]);
    }

    /**
     * Mark an order paid. Safe to call any number of times from any source (return URL, webhook, admin): the
     * conditional UPDATE lets exactly one caller win; only that caller takes stock, sends email and fires webhooks.
     * Returns true when this call changed the order.
     */
    public static function markPaid(int $id, string $paymentRef, string $via): bool
    {
        $now = now();
        $order = null;
        $shortfalls = [];
        $previous = (string)(self::find($id)['status'] ?? 'pending');
        $won = (bool)DB::transaction(static function () use ($id, $paymentRef, $via, $now, &$order, &$shortfalls): bool {
            $n = DB::query("UPDATE {{shop_orders}} SET status = 'paid', paid_at = :now, updated_at = :now2, payment_ref = :ref
                WHERE id = :id AND status IN ('pending', 'failed')", ['now' => $now, 'now2' => $now, 'ref' => mb_substr($paymentRef, 0, 191), 'id' => $id])->rowCount();
            if ($n !== 1) {
                return false;
            }
            $order = self::find($id);
            foreach ($order['items'] as $item) {
                $short = Product::decrementStock((int)$item['product_id'], (int)$item['qty']);
                if ($short > 0) {
                    $shortfalls[] = $item['title'] . ' (' . $short . ' more than in stock)';
                }
            }
            return true;
        });
        if (!$won) {
            $current = self::find($id);
            if ($current && $current['status'] === 'cancelled' && $via !== 'admin') {
                // Paid after it was cancelled (e.g. a very late bank confirmation): keep a trace and tell the shop.
                self::log($id, "Payment confirmed by $via (ref $paymentRef) but the order was already cancelled. Check and refund or reopen it.");
                if ($to = Shop::notifyEmail()) {
                    Mailer::send($to, 'Payment received for cancelled order ' . self::number($current),
                        '<p>A payment was confirmed for order ' . e(self::number($current)) . ', which had already been cancelled. Please review it in the dashboard.</p>');
                }
            }
            return false;
        }
        self::log($id, 'Payment confirmed by ' . $via . ($paymentRef !== '' ? ' (' . $paymentRef . ')' : '') . '.');
        if ($shortfalls) {
            self::log($id, 'Stock shortfall: ' . implode('; ', $shortfalls));
        }
        Product::purgePages(array_column($order['items'], 'product_id'));
        $order = self::find($id);
        self::sendEmails($order, 'paid');
        self::dispatch('shop.order_paid', $order);
        self::dispatch('shop.order_status_changed', $order, $previous);
        return true;
    }

    /**
     * Change the status (admin). Moving an unpaid order to paid/processing/completed runs the full payment flow.
     */
    public static function setStatus(int $id, string $status, bool $notify, string $by = 'admin'): bool
    {
        $order = self::find($id);
        if (!$order || !isset(self::STATUSES[$status]) || $order['status'] === $status) {
            return false;
        }
        // Moving an unpaid order to paid / processing / completed means the money arrived: run the payment flow
        // (stock, confirmation emails, webhooks) first, then continue to the requested status.
        if (in_array($status, ['paid', 'processing', 'completed'], true) && in_array($order['status'], ['pending', 'failed'], true)) {
            if (!self::markPaid($id, $order['payment_ref'] ?: 'manual', $by)) {
                return false;
            }
            if ($status === 'paid') {
                return true;
            }
            $order = self::find($id);
            if (!$order) {
                return false;
            }
            $notify = false; // the payment confirmation email was just sent
        }
        $previous = $order['status'];
        $n = DB::query('UPDATE {{shop_orders}} SET status = :s, updated_at = :now WHERE id = :id AND status = :old',
            ['s' => $status, 'now' => now(), 'id' => $id, 'old' => $order['status']])->rowCount();
        if ($n !== 1) {
            return false;
        }
        self::log($id, 'Status changed from ' . self::label($order['status']) . ' to ' . self::label($status) . ' by ' . $by . '.');
        $order = self::find($id);
        if ($notify) {
            self::sendEmails($order, 'status');
        }
        self::dispatch('shop.order_status_changed', $order, $previous);
        return true;
    }

    /** Mark an unpaid order failed (payment could not start). */
    public static function markFailed(int $id, string $reason): void
    {
        $n = DB::query("UPDATE {{shop_orders}} SET status = 'failed', updated_at = :now WHERE id = :id AND status = 'pending'",
            ['now' => now(), 'id' => $id])->rowCount();
        self::log($id, 'Payment failed: ' . $reason);
        if ($n === 1 && ($o = self::find($id))) {
            self::dispatch('shop.order_status_changed', $o, 'pending');
        }
    }

    /** Re-open a failed order for a new payment attempt. */
    public static function reopen(int $id): void
    {
        DB::query("UPDATE {{shop_orders}} SET status = 'pending', updated_at = :now WHERE id = :id AND status = 'failed'", ['now' => now(), 'id' => $id]);
    }

    public static function saveAdminNotes(int $id, string $notes): void
    {
        DB::update('shop_orders', ['admin_notes' => mb_substr($notes, 0, 5000), 'updated_at' => now()], ['id' => $id]);
    }

    /** Scheduled task: cancel online-payment orders left unpaid. Stock was never taken, so nothing is released. */
    public static function cancelStale(): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::UNPAID_HOURS * 3600);
        $ids = DB::col("SELECT id FROM {{shop_orders}} WHERE status IN ('pending', 'failed') AND provider IN ('stripe', 'razorpay') AND created_at < :c
            ORDER BY id LIMIT 500", ['c' => $cutoff]);
        $done = 0;
        foreach ($ids as $id) {
            $n = DB::query("UPDATE {{shop_orders}} SET status = 'cancelled', updated_at = :now WHERE id = :id AND status IN ('pending', 'failed')",
                ['now' => now(), 'id' => (int)$id])->rowCount();
            if ($n === 1) {
                $done++;
                self::log((int)$id, 'Cancelled automatically: not paid within ' . self::UNPAID_HOURS . ' hours.');
                if ($o = self::find((int)$id)) {
                    self::dispatch('shop.order_status_changed', $o, 'pending');
                }
            }
        }
        return $done;
    }

    /** Paid orders nobody has started processing yet (admin badge). */
    public static function newCount(): int
    {
        try {
            return (int)DB::val("SELECT COUNT(*) FROM {{shop_orders}} WHERE status = 'paid'");
        } catch (\Throwable) {
            return 0;
        }
    }

    // ------------------------------------------------------------------ notifications

    public static function webhookData(array $order): array
    {
        return [
            'id' => $order['id'],
            'number' => self::number($order),
            'status' => $order['status'],
            'customer' => ['name' => $order['customer_name'], 'email' => $order['customer_email'], 'phone' => $order['customer_phone']],
            'address' => $order['address'],
            'items' => $order['items'],
            'subtotal' => Money::toDecimal($order['subtotal_minor'], $order['decimals']),
            'shipping' => Money::toDecimal($order['shipping_minor'], $order['decimals']),
            'total' => Money::toDecimal($order['total_minor'], $order['decimals']),
            'total_minor' => $order['total_minor'],
            'currency' => $order['currency'],
            'provider' => $order['provider'],
            'payment_ref' => $order['payment_ref'],
            'notes' => (string)$order['notes'],
            'created_at' => $order['created_at'],
            'paid_at' => $order['paid_at'],
            'admin_url' => Admin::orderUrl((int)$order['id']),
        ];
    }

    private static function dispatch(string $event, ?array $order, ?string $previous = null): void
    {
        if (!$order || !class_exists(Webhooks::class)) {
            return;
        }
        $data = self::webhookData($order);
        if ($event === 'shop.order_status_changed') {
            $data['previous_status'] = $previous;
        }
        try {
            Webhooks::dispatch($event, $data);
        } catch (\Throwable $e) {
            error_log('Shop webhook dispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * Emails: "received" (manual payment orders), "paid" (customer + shop), "status" (customer, on admin change).
     */
    public static function sendEmails(array $order, string $kind): void
    {
        $site = (string)Options::get('site_title', '');
        $num = self::number($order);
        $shopTo = Shop::notifyEmail();
        $customer = filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL) ? $order['customer_email'] : '';
        $mail = static function (string $to, string $subject, string $heading, string $intro, bool $forShop) use ($order, $site): void {
            if ($to === '') {
                return;
            }
            $html = Shop::view('emails/order', ['order' => $order, 'heading' => $heading, 'intro' => $intro, 'site' => $site, 'forShop' => $forShop]);
            $opts = $forShop && filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL) ? ['reply_to' => $order['customer_email']] : [];
            if (!$forShop && ($reply = Shop::notifyEmail())) {
                $opts['reply_to'] = $reply;
            }
            if (!Mailer::send($to, $subject, $html, $opts)) {
                error_log('Shop: email "' . $subject . '" could not be sent: ' . (Mailer::lastError() ?? 'unknown error'));
            }
        };
        switch ($kind) {
            case 'received':
                $mail($customer, "Your order $num at $site", 'Thank you for your order',
                    'We have received your order. ' . trim((string)Shop::setting('manual_instructions')), false);
                $mail($shopTo, "New order $num (awaiting payment)", "New order $num", 'A new order was placed and is waiting for payment.', true);
                break;
            case 'paid':
                $mail($customer, "Order $num confirmed, $site", 'Payment received', 'Thank you! Your payment was received and your order is confirmed.', false);
                $mail($shopTo, "New paid order $num", "Paid order $num", 'A new order was paid and is ready to be processed.', true);
                break;
            case 'status':
                $mail($customer, "Order $num is now " . strtolower(self::label($order['status'])) . ", $site", 'Order update',
                    'The status of your order is now: ' . self::label($order['status']) . '.', false);
                break;
        }
    }
}
