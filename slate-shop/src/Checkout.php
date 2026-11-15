<?php
declare(strict_types=1);

namespace SlateModules\Shop;

use Slate\Csrf;
use Slate\Frontend;
use SlateModules\Shop\Payments\Razorpay;
use SlateModules\Shop\Payments\Stripe;

/** Public shop routes: cart, checkout, order status page and payment webhooks. */
final class Checkout
{
    public const ADDRESS_FIELDS = ['address_1' => 'Address', 'address_2' => 'Address line 2', 'city' => 'City', 'state' => 'State / region',
        'postcode' => 'Postcode / ZIP', 'country' => 'Country'];

    public static function routes(\Slate\Router $r): void
    {
        $r->get('/cart', [self::class, 'cartPage']);
        $r->post('/cart/add', [self::class, 'cartAdd']);
        $r->post('/cart/update', [self::class, 'cartUpdate']);
        $r->post('/cart/remove', [self::class, 'cartRemove']);
        $r->any('/checkout', [self::class, 'checkout']);
        $r->any('/order/{key:[a-f0-9]+}', [self::class, 'orderPage']);
        $r->post('/shop/webhook/{provider:[a-z]+}', [self::class, 'webhook']);
    }

    // ------------------------------------------------------------------ cart

    public static function cartPage(): void
    {
        Shop::noCache();
        Shop::maintenanceGuard();
        $notice = null;
        $error = null;
        if (isset($_GET['added']) && ($p = \Slate\Posts::find((int)$_GET['added'])) && Product::isProduct($p)) {
            $notice = '“' . $p['title'] . '” was added to your cart.';
        }
        if (isset($_GET['updated'])) {
            $notice = 'Your cart was updated.';
        }
        if (isset($_GET['error']) && is_string($_GET['error'])) {
            $error = Cart::message($_GET['error']);
        }
        $lines = Cart::lines();
        Shop::page('cart', (string)Shop::setting('cart_title') ?: 'Cart', [
            'lines' => $lines, 'totals' => Cart::totals($lines), 'notice' => $notice, 'error' => $error,
        ]);
    }

    private static function afterCartChange(?string $error, array $query = []): never
    {
        if (is_ajax()) {
            json_response(['ok' => $error === null || $error === 'limited', 'error' => $error ? Cart::message($error) : null,
                'count' => Cart::count(), 'cart_url' => Shop::cartUrl()], $error === null || $error === 'limited' ? 200 : 422);
        }
        if ($error !== null) {
            $query['error'] = $error;
        }
        $target = Shop::cartUrl();
        if ($error === null && Shop::setting('after_add') === 'back' && isset($query['added'])) {
            $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
            $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
            if ($ref !== '' && preg_match('#^https?://#i', $ref) && $host !== '' && $host === strtolower((string)parse_url(site_url(), PHP_URL_HOST))) {
                $ref = (string)preg_replace('/([?&])added=\d+(&|$)/', '$1', $ref);
                $ref = rtrim($ref, '?&');
                redirect($ref . (str_contains($ref, '?') ? '&' : '?') . 'added=' . (int)$query['added'], 303);
            }
        }
        redirect($target . ($query ? '?' . http_build_query($query) : ''), 303);
    }

    private static function intInput(string $key, int $default = 0): int
    {
        $v = $_POST[$key] ?? null;
        return is_string($v) && preg_match('/^\s*\d{1,6}\s*$/', $v) ? (int)$v : $default;
    }

    public static function cartAdd(): void
    {
        Shop::noCache();
        Shop::requireSameOrigin();
        $id = self::intInput('product_id');
        $qty = isset($_POST['qty']) ? self::intInput('qty', -1) : 1;
        $error = Cart::add($id, $qty);
        self::afterCartChange($error, $error === null || $error === 'limited' ? ['added' => $id] : []);
    }

    public static function cartUpdate(): void
    {
        Shop::noCache();
        Shop::requireSameOrigin();
        $error = null;
        $qtys = $_POST['qty'] ?? [];
        if (is_array($qtys)) {
            foreach ($qtys as $id => $qty) {
                $q = is_string($qty) && preg_match('/^\s*\d{1,6}\s*$/', $qty) ? (int)$qty : null;
                if ($q === null || !ctype_digit((string)$id)) {
                    $error ??= 'quantity';
                    continue;
                }
                $error = Cart::set((int)$id, $q) ?? $error;
            }
        }
        self::afterCartChange($error, $error === null ? ['updated' => 1] : []);
    }

    public static function cartRemove(): void
    {
        Shop::noCache();
        Shop::requireSameOrigin();
        Cart::remove(self::intInput('product_id'));
        self::afterCartChange(null, ['updated' => 1]);
    }

    // ------------------------------------------------------------------ checkout

    public static function checkout(): void
    {
        Shop::noCache();
        Shop::maintenanceGuard();
        $lines = Cart::lines();
        if (!$lines) {
            redirect(Shop::cartUrl(), 303);
        }
        $providers = Shop::providers();
        $errors = [];
        $data = self::blank();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Shop::requireSameOrigin();
            Csrf::check();
            [$data, $errors] = self::validate($_POST, $providers);
            foreach ($lines as $l) {
                if ($l['problem'] !== null) {
                    $errors['cart'] = '“' . $l['title'] . '”: ' . Cart::message($l['problem']) . ' Please review your cart.';
                    break;
                }
            }
            if (!$errors) {
                $totals = Cart::totals($lines);
                if ($totals['total'] <= 0 && $data['provider'] !== 'manual') {
                    $errors['provider'] = 'This order has nothing to pay online. Please choose another payment method.';
                } else {
                    self::placeOrder($data, $lines, $totals); // redirects on success
                    $errors['payment'] = self::$paymentError ?? 'The payment could not be started. Please try again.';
                }
            }
        }
        if (!$providers) {
            $errors['provider'] = 'Online ordering is not available right now. Please contact us to order.';
        }
        $lines = Cart::lines();
        Shop::page('checkout', (string)Shop::setting('checkout_title') ?: 'Checkout', [
            'lines' => $lines, 'totals' => Cart::totals($lines), 'data' => $data, 'errors' => $errors, 'providers' => $providers,
            'cancelled' => isset($_GET['cancelled']),
        ], $errors && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 422 : 200);
    }

    private static ?string $paymentError = null;

    private static function blank(): array
    {
        $addr = array_fill_keys(array_keys(self::ADDRESS_FIELDS), '');
        return ['name' => '', 'email' => '', 'phone' => '', 'notes' => '', 'provider' => '', 'billing' => $addr,
            'ship_different' => false, 'shipping' => $addr + ['name' => '']];
    }

    private static function clean(mixed $v, int $max): string
    {
        $v = is_string($v) ? $v : '';
        $v = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v));
        return mb_substr(strip_tags($v), 0, $max);
    }

    /** @return array{0:array, 1:array<string,string>} */
    public static function validate(array $in, array $providers): array
    {
        $d = self::blank();
        $errors = [];
        $d['name'] = self::clean($in['name'] ?? '', 120);
        $d['email'] = self::clean($in['email'] ?? '', 191);
        $d['phone'] = self::clean($in['phone'] ?? '', 40);
        $d['notes'] = self::clean($in['notes'] ?? '', 2000);
        $d['provider'] = is_string($in['provider'] ?? null) ? $in['provider'] : '';
        if ($d['name'] === '') {
            $errors['name'] = 'Please enter your name.';
        }
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        }
        if ($d['phone'] !== '' && !preg_match('/^[0-9 +().\/-]{5,40}$/', $d['phone'])) {
            $errors['phone'] = 'Please enter a valid phone number.';
        } elseif ($d['phone'] === '' && Shop::setting('require_phone')) {
            $errors['phone'] = 'Please enter your phone number.';
        }
        $needAddress = (bool)Shop::setting('require_address');
        $billing = is_array($in['billing'] ?? null) ? $in['billing'] : [];
        foreach (array_keys(self::ADDRESS_FIELDS) as $k) {
            $d['billing'][$k] = self::clean($billing[$k] ?? '', 191);
        }
        if ($needAddress) {
            foreach (['address_1', 'city', 'postcode', 'country'] as $k) {
                if ($d['billing'][$k] === '') {
                    $errors['billing_' . $k] = 'Please enter your ' . strtolower(self::ADDRESS_FIELDS[$k]) . '.';
                }
            }
        }
        $d['ship_different'] = !empty($in['ship_different']);
        if ($d['ship_different']) {
            $ship = is_array($in['shipping'] ?? null) ? $in['shipping'] : [];
            $d['shipping']['name'] = self::clean($ship['name'] ?? '', 120);
            foreach (array_keys(self::ADDRESS_FIELDS) as $k) {
                $d['shipping'][$k] = self::clean($ship[$k] ?? '', 191);
            }
            foreach (['name' => 'name', 'address_1' => 'address', 'city' => 'city', 'postcode' => 'postcode', 'country' => 'country'] as $k => $label) {
                if ($d['shipping'][$k] === '') {
                    $errors['shipping_' . $k] = 'Please enter the delivery ' . $label . '.';
                }
            }
        }
        if (!isset($providers[$d['provider']])) {
            if (count($providers) === 1) {
                $d['provider'] = (string)array_key_first($providers);
            } else {
                $errors['provider'] = 'Please choose a payment method.';
            }
        }
        return [$d, $errors];
    }

    /** Create the order and hand it to the payment provider. Returns only when the payment could not start. */
    private static function placeOrder(array $d, array $lines, array $totals): void
    {
        // Final stock check right before the order is written (stock is taken when the payment is confirmed).
        foreach ($lines as $l) {
            if (!$l['post'] || !Product::inStock($l['post'], $l['qty'])) {
                self::$paymentError = '“' . $l['title'] . '” no longer has enough stock. Please review your cart.';
                return;
            }
        }
        $order = Orders::create([
            'name' => $d['name'], 'email' => $d['email'], 'phone' => $d['phone'], 'notes' => $d['notes'],
            'billing' => $d['billing'], 'shipping' => $d['ship_different'] ? $d['shipping'] : null,
        ], $lines, $totals, $d['provider']);

        switch ($d['provider']) {
            case 'stripe':
                $res = Stripe::start($order);
                if (!empty($res['url'])) {
                    redirect($res['url'], 303);
                }
                Orders::markFailed($order['id'], (string)($res['error'] ?? 'unknown error'));
                self::$paymentError = (string)($res['error'] ?? 'The payment could not be started.');
                return;
            case 'razorpay':
                $res = Razorpay::start($order);
                if (empty($res['ok'])) {
                    Orders::markFailed($order['id'], (string)($res['error'] ?? 'unknown error'));
                    self::$paymentError = (string)($res['error'] ?? 'The payment could not be started.');
                    return;
                }
                redirect(Shop::orderUrl($order['order_key']), 303);
            default:
                Cart::clear();
                Orders::sendEmails($order, 'received');
                redirect(Shop::orderUrl($order['order_key']), 303);
        }
    }

    // ------------------------------------------------------------------ order page

    public static function orderPage(string $key): void
    {
        Shop::noCache();
        $order = Orders::findByKey($key);
        if (!$order) {
            Frontend::notFound();
            return;
        }
        Shop::maintenanceGuard();
        $message = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Shop::requireSameOrigin();
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'razorpay') {
                $ok = Razorpay::confirmReturn($order, $_POST);
                redirect(Shop::orderUrl($key) . ($ok ? '' : '?payment=invalid'), 303);
            }
            if ($action === 'pay' && in_array($order['status'], ['pending', 'failed'], true) && $order['provider'] === 'stripe') {
                Orders::reopen($order['id']);
                $res = Stripe::start(Orders::find($order['id']));
                if (!empty($res['url'])) {
                    redirect($res['url'], 303);
                }
                Orders::markFailed($order['id'], (string)($res['error'] ?? 'unknown error'));
                redirect(Shop::orderUrl($key) . '?payment=error', 303);
            }
            if ($action === 'pay' && in_array($order['status'], ['pending', 'failed'], true) && $order['provider'] === 'razorpay'
                && ($order['status'] === 'failed' || $order['provider_ref'] === '')) {
                Orders::reopen($order['id']);
                $res = Razorpay::start(Orders::find($order['id']));
                if (empty($res['ok'])) {
                    Orders::markFailed($order['id'], (string)($res['error'] ?? 'unknown error'));
                }
                redirect(Shop::orderUrl($key) . (empty($res['ok']) ? '?payment=error' : ''), 303);
            }
            redirect(Shop::orderUrl($key), 303);
        }
        if ($order['status'] === 'pending' && $order['provider'] === 'stripe' && is_string($_GET['session_id'] ?? null)) {
            Stripe::confirmReturn($order, $_GET['session_id']);
            $order = Orders::find($order['id']);
        }
        if (in_array($order['status'], ['paid', 'processing', 'completed'], true) && !Cart::isEmpty()) {
            Cart::clear();
        }
        $payment = (string)($_GET['payment'] ?? '');
        if ($payment === 'invalid') {
            $message = 'We could not verify that payment. If money was taken, it will be confirmed automatically or we will contact you.';
        } elseif ($payment === 'error') {
            $message = 'The payment could not be started. Please try again in a moment or contact us.';
        }
        Shop::page('order', 'Order ' . Orders::number($order), ['order' => $order, 'message' => $message]);
    }

    // ------------------------------------------------------------------ webhooks

    public static function webhook(string $provider): void
    {
        Shop::noCache();
        match ($provider) {
            'stripe' => Stripe::webhook(),
            'razorpay' => Razorpay::webhook(),
            default => json_response(['ok' => false, 'error' => 'Unknown provider.'], 404),
        };
    }
}
