<?php
declare(strict_types=1);

namespace SlateModules\Shop\Payments;

use Slate\Http;
use SlateModules\Shop\Money;
use SlateModules\Shop\Orders;
use SlateModules\Shop\Shop;

/**
 * Stripe Checkout (hosted payment page). The order is marked paid only after Stripe confirms it: either the
 * signed webhook (checkout.session.completed / async_payment_succeeded) or, on the return URL, a server-side
 * lookup of the Checkout Session. Both paths go through the idempotent Orders::markPaid().
 */
final class Stripe
{
    private const API = 'https://api.stripe.com/v1';
    /** Seconds a webhook signature timestamp may differ from our clock. */
    public const TOLERANCE = 300;

    public static function mode(): string
    {
        return Shop::setting('stripe_mode') === 'live' ? 'live' : 'test';
    }

    public static function secretKey(?string $mode = null): string
    {
        return Shop::secret('stripe_' . ($mode ?? self::mode()) . '_secret');
    }

    public static function configured(): bool
    {
        return self::secretKey() !== '';
    }

    /** Mode a Stripe object id belongs to (cs_test_… / cs_live_…), falling back to the current mode. */
    private static function modeOf(string $id): string
    {
        return str_contains($id, '_live_') ? 'live' : (str_contains($id, '_test_') ? 'test' : self::mode());
    }

    /**
     * Create a Checkout Session for a pending order.
     * @return array{url?:string, error?:string}
     */
    public static function start(array $order): array
    {
        $key = self::secretKey();
        if ($key === '') {
            return ['error' => 'Card payments are not configured.'];
        }
        $currency = strtolower((string)$order['currency']);
        $dec = (int)$order['decimals'];
        $lineItems = [];
        foreach ($order['items'] as $item) {
            $lineItems[] = [
                'quantity' => (int)$item['qty'],
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => Money::providerAmount((int)$item['unit_minor'], $currency, $dec),
                    'product_data' => ['name' => mb_substr((string)$item['title'], 0, 250) ?: 'Product'],
                ],
            ];
        }
        if ($order['shipping_minor'] > 0) {
            $lineItems[] = ['quantity' => 1, 'price_data' => ['currency' => $currency,
                'unit_amount' => Money::providerAmount($order['shipping_minor'], $currency, $dec), 'product_data' => ['name' => 'Shipping']]];
        }
        $orderUrl = Shop::orderUrl($order['order_key'], true);
        $params = [
            'mode' => 'payment',
            'line_items' => $lineItems,
            'client_reference_id' => (string)$order['id'],
            'metadata' => ['order_key' => $order['order_key'], 'order_id' => (string)$order['id']],
            'payment_intent_data' => ['metadata' => ['order_key' => $order['order_key'], 'order_id' => (string)$order['id']]],
            'success_url' => $orderUrl . (str_contains($orderUrl, '?') ? '&' : '?') . 'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => Shop::checkoutUrl(true) . '?cancelled=1',
        ];
        if (filter_var($order['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $params['customer_email'] = $order['customer_email'];
        }
        // http_build_query encodes {CHECKOUT_SESSION_ID} braces, which Stripe expects literally: restore them.
        $body = str_replace(['%7BCHECKOUT_SESSION_ID%7D'], ['{CHECKOUT_SESSION_ID}'], http_build_query($params, '', '&', PHP_QUERY_RFC1738));
        $r = Http::request('POST', self::API . '/checkout/sessions', [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/x-www-form-urlencoded',
        ], $body, 30);
        $d = json_decode($r['body'], true);
        $d = is_array($d) ? $d : [];
        if ($r['status'] < 200 || $r['status'] >= 300 || empty($d['url']) || empty($d['id'])) {
            $msg = (string)($d['error']['message'] ?? $r['error'] ?? ('HTTP ' . $r['status']));
            return ['error' => 'The card payment could not be started: ' . self::redact($msg)];
        }
        Orders::setProviderRef($order['id'], 'stripe', (string)$d['id'], ['stripe_mode' => self::modeOf((string)$d['id'])]);
        return ['url' => (string)$d['url']];
    }

    /** Never echo key material back to anyone. */
    private static function redact(string $msg): string
    {
        return (string)preg_replace('/\b(sk|rk|pk|whsec)_[A-Za-z0-9_*]+/', '[key]', $msg);
    }

    /** Return URL: ask Stripe about the session before trusting anything. */
    public static function confirmReturn(array $order, string $sessionId): bool
    {
        if ($order['provider'] !== 'stripe' || !preg_match('/^cs_[A-Za-z0-9_]{1,250}$/', $sessionId)) {
            return false;
        }
        $key = self::secretKey(self::modeOf($sessionId));
        if ($key === '') {
            return false;
        }
        $r = Http::request('GET', self::API . '/checkout/sessions/' . rawurlencode($sessionId), ['Authorization: Bearer ' . $key], null, 30);
        $s = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($s) || !hash_equals($order['order_key'], (string)($s['metadata']['order_key'] ?? ''))) {
            return false;
        }
        return self::completeFromSession($s, 'Stripe return');
    }

    /**
     * Mark the matching order paid when a session object (from a signed webhook or fetched from the API with our
     * key, so it is authentic) says it is paid and amount and currency match. The order is found through the
     * metadata we set ourselves; it may be an earlier session of the same order (customer retried).
     */
    private static function completeFromSession(array $s, string $via): bool
    {
        if (($s['object'] ?? '') !== 'checkout.session' || ($s['payment_status'] ?? '') !== 'paid') {
            return false;
        }
        $order = Orders::findByKey((string)($s['metadata']['order_key'] ?? ''));
        if (!$order || $order['provider'] !== 'stripe') {
            return false;
        }
        $expected = Money::providerAmount($order['total_minor'], (string)$order['currency'], (int)$order['decimals']);
        if ((int)($s['amount_total'] ?? -1) !== $expected || strtolower((string)($s['currency'] ?? '')) !== strtolower((string)$order['currency'])) {
            Orders::log($order['id'], "$via: amount or currency mismatch, payment not applied automatically. Check it in Stripe.");
            return false;
        }
        $ref = is_string($s['payment_intent'] ?? null) ? $s['payment_intent'] : (string)($s['id'] ?? '');
        Orders::markPaid($order['id'], $ref, $via);
        return true;
    }

    /**
     * Verify a Stripe-Signature header ("t=…,v1=…,v1=…"): HMAC-SHA256 of "t.payload" with the endpoint secret,
     * compared in constant time, timestamp within the tolerance.
     */
    public static function verifySignature(string $payload, string $header, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) {
                $t = (int)$v;
            } elseif ($k === 'v1' && $v !== '') {
                $sigs[] = $v;
            }
        }
        if ($t === null || !$sigs || abs(($now ?? time()) - $t) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        $ok = false;
        foreach ($sigs as $sig) {
            $ok = hash_equals($expected, $sig) || $ok; // check all without short-circuiting
        }
        return $ok;
    }

    /** POST /shop/webhook/stripe */
    public static function webhook(): never
    {
        $payload = (string)file_get_contents('php://input');
        $header = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
        $valid = false;
        foreach (['test', 'live'] as $mode) {
            if (self::verifySignature($payload, $header, Shop::secret('stripe_' . $mode . '_webhook'))) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            json_response(['ok' => false, 'error' => 'Invalid signature.'], 400);
        }
        $event = json_decode($payload, true);
        $type = is_array($event) ? (string)($event['type'] ?? '') : '';
        if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)
            && is_array($event['data']['object'] ?? null)) {
            self::completeFromSession($event['data']['object'], 'Stripe webhook');
        } elseif ($type === 'checkout.session.async_payment_failed' && is_array($event['data']['object'] ?? null)) {
            $s = $event['data']['object'];
            $order = Orders::findByKey((string)($s['metadata']['order_key'] ?? ''));
            if ($order && $order['provider'] === 'stripe' && hash_equals((string)$order['provider_ref'], (string)($s['id'] ?? ''))) {
                Orders::markFailed($order['id'], 'Stripe reported the delayed payment as failed.');
            }
        }
        json_response(['ok' => true]);
    }
}
