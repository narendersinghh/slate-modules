<?php
declare(strict_types=1);

namespace SlateModules\Shop;

use Slate\Auth;
use Slate\DB;
use Slate\Session;

/** Dashboard: Orders inbox and Shop settings (POST handlers; CSRF is checked by the admin router). */
final class Admin
{
    public const ORDERS = 'shop-orders';
    public const SETTINGS = 'shop-settings';

    public static function ordersUrl(array $query = []): string
    {
        $query = array_filter($query, static fn($v) => $v !== '' && $v !== null);
        return admin_url('m/' . self::ORDERS) . ($query ? '?' . http_build_query($query) : '');
    }

    /** WHERE clause for the orders list (?status= &q=). */
    public static function filterSql(array $q): array
    {
        $w = [];
        $p = [];
        $status = (string)($q['status'] ?? '');
        if (isset(Orders::STATUSES[$status])) {
            $w[] = 'status = :status';
            $p['status'] = $status;
        }
        $search = trim((string)($q['q'] ?? ''));
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

    /** POST /admin/m/shop-orders/action */
    public static function orderAction(): never
    {
        Auth::require('edit_others_posts');
        $id = (int)($_POST['id'] ?? 0);
        $order = Orders::find($id);
        if (!$order) {
            Session::flash('error', 'Order not found.');
            redirect(self::ordersUrl());
        }
        $do = (string)($_POST['do'] ?? '');
        $who = (string)(Auth::user()['display_name'] ?? 'admin') ?: 'admin';
        if ($do === 'status') {
            $status = (string)($_POST['status'] ?? '');
            if (!isset(Orders::STATUSES[$status])) {
                Session::flash('error', 'Choose a valid status.');
            } elseif ($status === $order['status']) {
                Session::flash('success', 'The order already has that status.');
            } elseif (Orders::setStatus($id, $status, !empty($_POST['notify']), $who)) {
                if (in_array($status, ['paid', 'processing', 'completed'], true) && in_array($order['status'], ['pending', 'failed'], true)) {
                    Session::flash('success', 'Payment recorded: stock was updated and confirmation emails were sent. Status: ' . Orders::label($status) . '.');
                } else {
                    Session::flash('success', 'Status changed to ' . Orders::label($status) . (!empty($_POST['notify']) ? ' and the customer was emailed.' : '.'));
                }
                log_activity('shop_order_status', Orders::number($order) . ' → ' . $status);
            } else {
                Session::flash('error', 'The status could not be changed (it may have changed meanwhile). Please reload.');
            }
        } elseif ($do === 'notes') {
            Orders::saveAdminNotes($id, trim(str_replace("\r\n", "\n", (string)($_POST['admin_notes'] ?? ''))));
            Session::flash('success', 'Notes saved.');
        } elseif ($do === 'resend') {
            Orders::sendEmails($order, in_array($order['status'], ['paid', 'processing', 'completed'], true) ? 'paid' : 'received');
            Orders::log($id, 'Confirmation email resent by ' . $who . '.');
            Session::flash('success', 'Email sent again.');
        }
        redirect(self::ordersUrl(['id' => $id]));
    }

    /** POST /admin/m/shop-settings/save */
    public static function saveSettings(): never
    {
        Auth::require('manage_settings');
        $in = $_POST;
        $str = static fn(string $k, int $max = 191): string => mb_substr(trim(strip_tags((string)($in[$k] ?? ''))), 0, $max);
        $text = static fn(string $k, int $max = 4000): string => mb_substr(trim(str_replace("\r\n", "\n", strip_tags((string)($in[$k] ?? '')))), 0, $max);
        $errors = [];
        $notices = [];

        $s = Shop::settings();
        $currency = strtoupper($str('currency', 3));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors[] = 'Currency must be a three-letter ISO code such as USD, EUR or INR.';
            $currency = (string)$s['currency'];
        }
        $s['currency'] = $currency;
        $s['currency_symbol'] = $str('currency_symbol', 8);
        $s['currency_position'] = in_array($in['currency_position'] ?? '', ['before', 'after', 'before_space', 'after_space'], true) ? $in['currency_position'] : 'before';
        $decimals = max(0, min(3, (int)($in['decimals'] ?? 2)));
        $iso = Money::isoExponent($currency);
        if ($decimals > $iso) {
            $notices[] = "$currency has $iso decimal" . ($iso === 1 ? '' : 's') . ' at the payment providers, so prices use ' . $iso . '.';
            $decimals = $iso;
        }
        $oldDecimals = Money::decimals();
        $s['decimals'] = $decimals;
        $s['thousand_sep'] = mb_substr((string)($in['thousand_sep'] ?? ','), 0, 2);
        $s['decimal_sep'] = mb_substr((string)($in['decimal_sep'] ?? '.'), 0, 2) ?: '.';
        $shopEmail = $str('shop_email');
        if ($shopEmail !== '' && !filter_var($shopEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The notification email is not a valid address.';
            $shopEmail = (string)$s['shop_email'];
        }
        $s['shop_email'] = $shopEmail;

        // Amounts: validated and stored as canonical decimals in the new precision.
        Shop::saveSettings(['decimals' => $decimals] + $s); // so Money parses with the new decimals
        foreach (['shipping_flat' => 'Shipping rate', 'free_shipping_over' => 'Free shipping threshold'] as $k => $label) {
            $raw = $str($k, 30);
            if ($raw === '') {
                $s[$k] = '';
            } elseif (($m = Money::toMinor($raw)) === null) {
                $errors[] = "$label must be an amount such as 4.99.";
            } else {
                $s[$k] = Money::toDecimal($m);
            }
        }
        foreach (['require_address', 'require_phone', 'manual_enabled', 'stripe_enabled', 'razorpay_enabled', 'auto_append'] as $k) {
            $s[$k] = !empty($in[$k]);
        }
        $s['after_add'] = ($in['after_add'] ?? '') === 'back' ? 'back' : 'cart';
        $s['stripe_mode'] = ($in['stripe_mode'] ?? '') === 'live' ? 'live' : 'test';
        $s['razorpay_mode'] = ($in['razorpay_mode'] ?? '') === 'live' ? 'live' : 'test';
        foreach (['manual_title', 'stripe_title', 'razorpay_title', 'cart_title', 'checkout_title', 'add_to_cart_label', 'order_button_label'] as $k) {
            $s[$k] = $str($k, 120) ?: (string)Shop::DEFAULTS[$k];
        }
        foreach (['manual_instructions', 'cart_empty', 'checkout_intro', 'thankyou_text'] as $k) {
            $s[$k] = $text($k);
        }
        foreach (['test', 'live'] as $mode) {
            $keyId = $str("razorpay_{$mode}_key_id", 60);
            if ($keyId !== '' && !preg_match('/^rzp_' . $mode . '_[A-Za-z0-9]+$/', $keyId)) {
                $errors[] = "The Razorpay $mode key id should start with rzp_{$mode}_.";
            }
            $s["razorpay_{$mode}_key_id"] = $keyId;
        }

        // Secrets: blank keeps the saved value, "clear" removes it.
        $changes = [];
        foreach (array_keys(Shop::SECRET_KEYS) as $k) {
            $v = trim((string)($in['secret'][$k] ?? ''));
            if (!empty($in['clear'][$k])) {
                $changes[$k] = null;
            } elseif ($v !== '') {
                if (preg_match('/[\s\x00-\x1F]/', $v) || strlen($v) > 255) {
                    $errors[] = 'One of the keys contains spaces or is too long; it was not saved.';
                    continue;
                }
                if (str_starts_with($k, 'stripe_') && str_ends_with($k, '_secret') && !preg_match('/^(sk|rk)_' . (str_contains($k, '_live_') ? 'live' : 'test') . '_/', $v)) {
                    $errors[] = 'The Stripe ' . (str_contains($k, '_live_') ? 'live' : 'test') . ' secret key should start with sk_' . (str_contains($k, '_live_') ? 'live' : 'test') . '_ (or rk_ for a restricted key).';
                    continue;
                }
                if (str_starts_with($k, 'stripe_') && str_ends_with($k, '_webhook') && !str_starts_with($v, 'whsec_')) {
                    $errors[] = 'Stripe webhook signing secrets start with whsec_.';
                    continue;
                }
                $changes[$k] = $v;
            }
        }
        Shop::saveSettings($s);
        if ($changes) {
            Shop::saveSecrets($changes);
        }
        Shop::ensureCacheBypass();
        if ($oldDecimals !== $decimals) {
            $notices[] = 'Decimals changed: check your product prices (they are stored as entered).';
        }
        if ($s['stripe_enabled'] && !Payments\Stripe::configured()) {
            $notices[] = 'Stripe is switched on but has no ' . $s['stripe_mode'] . ' secret key yet, so it is not offered at checkout.';
        }
        if ($s['razorpay_enabled'] && !Payments\Razorpay::configured()) {
            $notices[] = 'Razorpay is switched on but its ' . $s['razorpay_mode'] . ' keys are incomplete, so it is not offered at checkout.';
        }
        if (!Shop::providers()) {
            $notices[] = 'No payment method is available: customers cannot check out.';
        }
        \Slate\PageCache::clear(); // labels, prices format and buttons appear on cached product pages
        log_activity('shop_settings_saved');
        Session::flash($errors ? 'error' : 'success', $errors ? implode(' ', $errors) : 'Shop settings saved.' . ($notices ? ' ' . implode(' ', $notices) : ''));
        if ($errors && $notices) {
            Session::flash('success', implode(' ', $notices));
        }
        redirect(admin_url('m/' . self::SETTINGS));
    }

    /** Counts per status for the list tabs. */
    public static function counts(): array
    {
        try {
            return array_map('intval', array_column(DB::all('SELECT status, COUNT(*) AS c FROM {{shop_orders}} GROUP BY status'), 'c', 'status'));
        } catch (\Throwable) {
            return [];
        }
    }
}
