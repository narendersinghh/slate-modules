<?php
declare(strict_types=1);

namespace SlateModules\Shop\Payments;

use Slate\Http;
use SlateModules\Shop\Money;
use SlateModules\Shop\Orders;
use SlateModules\Shop\Shop;

/**
 * Razorpay Standard Checkout: a Razorpay Order is created server-side, the order page opens Razorpay's official
 * checkout.js, and the returned payment is accepted only with a valid signature
 * HMAC-SHA256(order_id|payment_id, key_secret). The signed webhook (order.paid / payment.captured) is the
 * fallback when the customer closes the window early. Both paths use the idempotent Orders::markPaid().
 */
final class Razorpay
{
    private const API = 'https://api.razorpay.com/v1';
    public const SCRIPT = 'https://checkout.razorpay.com/v1/checkout.js';

    public static function mode(): string
    {
        return Shop::setting('razorpay_mode') === 'live' ? 'live' : 'test';
    }

    /** @return array{0:string, 1:string} key id, key secret */
    public static function keys(?string $mode = null): array
    {
        $mode ??= self::mode();
        return [trim((string)Shop::setting('razorpay_' . $mode . '_key_id')), Shop::secret('razorpay_' . $mode . '_key_secret')];
    }

    public static function configured(): bool
    {
        [$id, $secret] = self::keys();
        return $id !== '' && $secret !== '';
    }

    /**
     * Create the Razorpay Order for a pending shop order.
     * @return array{ok?:bool, error?:string}
     */
    public static function start(array $order): array
    {
        $mode = self::mode();
        [$keyId, $secret] = self::keys($mode);
        if ($keyId === '' || $secret === '') {
            return ['error' => 'Razorpay is not configured.'];
        }
        $r = Http::json('POST', self::API . '/orders', ['Authorization: Basic ' . base64_encode($keyId . ':' . $secret)], [
            'amount' => Money::providerAmount($order['total_minor'], (string)$order['currency'], (int)$order['decimals']),
            'currency' => strtoupper((string)$order['currency']),
            'receipt' => 'order-' . $order['id'],
            'notes' => ['order_id' => (string)$order['id'], 'order_key' => $order['order_key']],
        ], 30);
        $d = is_array($r['json'] ?? null) ? $r['json'] : [];
        if ($r['status'] < 200 || $r['status'] >= 300 || empty($d['id'])) {
            $msg = (string)($d['error']['description'] ?? $r['error'] ?? ('HTTP ' . $r['status']));
            return ['error' => 'The payment could not be started: ' . $msg];
        }
        Orders::setProviderRef($order['id'], 'razorpay', (string)$d['id'], ['razorpay_mode' => $mode]);
        return ['ok' => true];
    }

    /** Options for checkout.js on the order page. */
    public static function checkoutOptions(array $order): array
    {
        $mode = ($order['meta']['razorpay_mode'] ?? '') === 'live' ? 'live' : 'test';
        [$keyId] = self::keys($mode);
        return [
            'key' => $keyId,
            'amount' => Money::providerAmount($order['total_minor'], (string)$order['currency'], (int)$order['decimals']),
            'currency' => strtoupper((string)$order['currency']),
            'name' => (string)\Slate\Options::get('site_title', ''),
            'description' => 'Order ' . Orders::number($order),
            'order_id' => (string)$order['provider_ref'],
            'prefill' => ['name' => $order['customer_name'], 'email' => $order['customer_email'], 'contact' => $order['customer_phone']],
            'notes' => ['order_id' => (string)$order['id']],
        ];
    }

    /** Constant-time check of the checkout signature against every configured key secret. */
    public static function validPaymentSignature(string $rzpOrderId, string $paymentId, string $signature): bool
    {
        if ($rzpOrderId === '' || $paymentId === '' || $signature === '') {
            return false;
        }
        $ok = false;
        foreach (['test', 'live'] as $mode) {
            $secret = self::keys($mode)[1];
            if ($secret !== '') {
                $ok = hash_equals(hash_hmac('sha256', $rzpOrderId . '|' . $paymentId, $secret), $signature) || $ok;
            }
        }
        return $ok;
    }

    /** POST back from checkout.js on the order page. */
    public static function confirmReturn(array $order, array $post): bool
    {
        $rzpOrder = (string)($post['razorpay_order_id'] ?? '');
        $payment = (string)($post['razorpay_payment_id'] ?? '');
        $sig = (string)($post['razorpay_signature'] ?? '');
        if ($order['provider'] !== 'razorpay' || $order['provider_ref'] === '' || !hash_equals((string)$order['provider_ref'], $rzpOrder)) {
            return false;
        }
        if (!self::validPaymentSignature($rzpOrder, $payment, $sig)) {
            Orders::log($order['id'], 'Razorpay return with an invalid signature was ignored.');
            return false;
        }
        Orders::markPaid($order['id'], $payment, 'Razorpay checkout');
        return true;
    }

    public static function validWebhookSignature(string $payload, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }
        $ok = false;
        foreach (['test', 'live'] as $mode) {
            $secret = Shop::secret('razorpay_' . $mode . '_webhook');
            if ($secret !== '') {
                $ok = hash_equals(hash_hmac('sha256', $payload, $secret), $signature) || $ok;
            }
        }
        return $ok;
    }

    /** POST /shop/webhook/razorpay */
    public static function webhook(): never
    {
        $payload = (string)file_get_contents('php://input');
        if (!self::validWebhookSignature($payload, (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? ''))) {
            json_response(['ok' => false, 'error' => 'Invalid signature.'], 400);
        }
        $e = json_decode($payload, true);
        $event = is_array($e) ? (string)($e['event'] ?? '') : '';
        $payment = is_array($e['payload']['payment']['entity'] ?? null) ? $e['payload']['payment']['entity'] : [];
        $rzpOrderId = '';
        $amount = -1;
        $currency = '';
        if ($event === 'order.paid' && is_array($e['payload']['order']['entity'] ?? null)) {
            $o = $e['payload']['order']['entity'];
            $rzpOrderId = (string)($o['id'] ?? '');
            $amount = (int)($o['amount_paid'] ?? -1);
            $currency = (string)($o['currency'] ?? '');
        } elseif ($event === 'payment.captured' && $payment) {
            $rzpOrderId = (string)($payment['order_id'] ?? '');
            $amount = (int)($payment['amount'] ?? -1);
            $currency = (string)($payment['currency'] ?? '');
        } elseif ($event === 'payment.failed' && $payment) {
            $order = Orders::findByRef('razorpay', (string)($payment['order_id'] ?? ''));
            if ($order) {
                Orders::log($order['id'], 'Razorpay payment attempt failed: ' . mb_substr((string)($payment['error_description'] ?? 'unknown reason'), 0, 200));
            }
        }
        if ($rzpOrderId !== '') {
            $order = Orders::findByRef('razorpay', $rzpOrderId);
            if ($order) {
                $expected = Money::providerAmount($order['total_minor'], (string)$order['currency'], (int)$order['decimals']);
                if ($amount === $expected && strtoupper($currency) === strtoupper((string)$order['currency'])) {
                    Orders::markPaid($order['id'], (string)($payment['id'] ?? $rzpOrderId), 'Razorpay webhook');
                } else {
                    Orders::log($order['id'], 'Razorpay webhook: amount or currency mismatch, payment not applied automatically.');
                }
            }
        }
        json_response(['ok' => true]);
    }
}
