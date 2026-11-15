<?php
declare(strict_types=1);

namespace SlateModules\Shop;

use Slate\Options;
use Slate\Session;

/**
 * Shop settings, declared for core's settings screen (Settings → Modules → Shop). Each value is its own option
 * ("shop_currency", "shop_stripe_test_secret", …); secrets are stored encrypted by core (Crypto), as before.
 */
final class Settings
{
    /** Field list for Modules::settings(). */
    public static function fields(): array
    {
        $k = static fn(string $key): string => Shop::optionKey($key);
        $d = Shop::DEFAULTS;
        $mode = ['test' => 'Test (no real money)', 'live' => 'Live'];
        $fields = [
            ['type' => 'heading', 'label' => 'Store', 'help' => 'Currency and how prices are shown.'],
            ['key' => $k('currency'), 'type' => 'text', 'label' => 'Currency', 'default' => $d['currency'], 'placeholder' => 'USD', 'pattern' => '^[A-Za-z]{3}$',
                'help' => 'Three-letter ISO code (USD, EUR, GBP, INR…). Payments are charged in this currency.'],
            ['key' => $k('currency_symbol'), 'type' => 'text', 'label' => 'Currency symbol', 'default' => $d['currency_symbol'], 'help' => 'Leave empty to show the code.'],
            ['key' => $k('currency_position'), 'type' => 'select', 'label' => 'Symbol position', 'default' => $d['currency_position'],
                'options' => ['before' => 'Before ($10.00)', 'before_space' => 'Before, with a space ($ 10.00)', 'after' => 'After (10.00$)', 'after_space' => 'After, with a space (10.00 $)']],
            ['key' => $k('decimals'), 'type' => 'select', 'label' => 'Decimals', 'default' => $d['decimals'], 'options' => ['0' => '0', '1' => '1', '2' => '2', '3' => '3'],
                'help' => 'Never more than the currency has (e.g. 0 for JPY).'],
            ['key' => $k('thousand_sep'), 'type' => 'select', 'label' => 'Thousands separator', 'default' => $d['thousand_sep'],
                'options' => [',' => 'Comma (1,000)', '.' => 'Dot (1.000)', "\u{00A0}" => 'Space (1 000)', "'" => 'Apostrophe (1\'000)', '' => 'None (1000)']],
            ['key' => $k('decimal_sep'), 'type' => 'select', 'label' => 'Decimal separator', 'default' => $d['decimal_sep'], 'options' => ['.' => 'Dot (9.99)', ',' => 'Comma (9,99)']],

            ['type' => 'heading', 'label' => 'Product pages'],
            ['key' => $k('auto_append'), 'type' => 'toggle', 'label' => 'Show price and button automatically', 'default' => $d['auto_append'],
                'help' => 'Adds price, note, “Add to cart” and gallery below the product text. Turn off when your template places them with {{shop:price}} and {{shop:add_to_cart}}.'],
            ['key' => $k('add_to_cart_label'), 'type' => 'text', 'label' => 'Button text', 'default' => $d['add_to_cart_label']],
            ['key' => $k('after_add'), 'type' => 'select', 'label' => 'After “Add to cart”', 'default' => $d['after_add'],
                'options' => ['cart' => 'Go to the cart', 'back' => 'Stay on the product page']],

            ['type' => 'heading', 'label' => 'Checkout', 'help' => 'Shipping, what customers fill in and the texts on the cart, checkout and order pages.'],
            ['key' => $k('shipping_flat'), 'type' => 'text', 'label' => 'Flat shipping rate', 'default' => '', 'placeholder' => '0.00', 'pattern' => '^[0-9][0-9 .,\']*$',
                'help' => 'Added once per order. Empty = no shipping charge.'],
            ['key' => $k('free_shipping_over'), 'type' => 'text', 'label' => 'Free shipping from', 'default' => '', 'pattern' => '^[0-9][0-9 .,\']*$',
                'help' => 'Orders with at least this subtotal ship free. Empty = never.'],
            ['key' => $k('require_address'), 'type' => 'toggle', 'label' => 'Ask for an address', 'default' => $d['require_address'],
                'help' => 'Turn off if you only sell digital goods or services.'],
            ['key' => $k('require_phone'), 'type' => 'toggle', 'label' => 'Require a phone number', 'default' => $d['require_phone']],
            ['key' => $k('cart_title'), 'type' => 'text', 'label' => 'Cart page title', 'default' => $d['cart_title']],
            ['key' => $k('cart_empty'), 'type' => 'textarea', 'label' => 'Empty cart text', 'default' => $d['cart_empty'], 'rows' => 2],
            ['key' => $k('checkout_title'), 'type' => 'text', 'label' => 'Checkout page title', 'default' => $d['checkout_title']],
            ['key' => $k('checkout_intro'), 'type' => 'textarea', 'label' => 'Checkout introduction', 'default' => '', 'rows' => 2, 'help' => 'Optional text above the checkout form.'],
            ['key' => $k('order_button_label'), 'type' => 'text', 'label' => 'Order button text', 'default' => $d['order_button_label']],
            ['key' => $k('thankyou_text'), 'type' => 'textarea', 'label' => 'Thank-you text', 'default' => $d['thankyou_text'], 'rows' => 2,
                'help' => 'Shown on the order page once the order is placed or paid.'],

            ['type' => 'heading', 'label' => 'Payments', 'help' => 'Turn on at least one way to pay. Webhook addresses are listed below the form.'],
            ['key' => $k('manual_enabled'), 'type' => 'toggle', 'label' => 'Manual payment', 'default' => $d['manual_enabled'],
                'help' => 'Bank transfer, cash on delivery, invoice… Orders stay “Pending payment” until you mark them paid.'],
            ['key' => $k('manual_title'), 'type' => 'text', 'label' => 'Name at checkout', 'default' => $d['manual_title'], 'show_if' => $k('manual_enabled') . '=1'],
            ['key' => $k('manual_instructions'), 'type' => 'textarea', 'label' => 'Payment instructions', 'default' => $d['manual_instructions'], 'rows' => 4,
                'show_if' => $k('manual_enabled') . '=1', 'help' => 'Shown on the order page and in the order email (bank details, what happens next).'],

            ['key' => $k('stripe_enabled'), 'type' => 'toggle', 'label' => 'Stripe Checkout', 'default' => $d['stripe_enabled'],
                'help' => 'Customers pay on Stripe’s hosted page (cards, wallets and the methods enabled in your Stripe account).'],
            ['key' => $k('stripe_mode'), 'type' => 'select', 'label' => 'Stripe mode', 'default' => $d['stripe_mode'], 'options' => $mode, 'show_if' => $k('stripe_enabled') . '=1'],
            ['key' => $k('stripe_title'), 'type' => 'text', 'label' => 'Name at checkout', 'default' => $d['stripe_title'], 'show_if' => $k('stripe_enabled') . '=1'],
        ];
        foreach (['stripe_test_secret' => 'Stripe → Developers → API keys. Starts with sk_test_ (or rk_test_).', 'stripe_test_webhook' => 'Starts with whsec_.',
            'stripe_live_secret' => 'Starts with sk_live_ (or rk_live_).', 'stripe_live_webhook' => 'Starts with whsec_.'] as $s => $help) {
            $fields[] = ['key' => $k($s), 'type' => 'secret', 'label' => 'Stripe ' . lcfirst(Shop::SECRET_KEYS[$s]), 'default' => '', 'help' => $help,
                'show_if' => $k('stripe_enabled') . '=1'];
        }
        $fields[] = ['key' => $k('razorpay_enabled'), 'type' => 'toggle', 'label' => 'Razorpay', 'default' => $d['razorpay_enabled'],
            'help' => 'UPI, cards, netbanking and wallets through Razorpay’s checkout window.'];
        $fields[] = ['key' => $k('razorpay_mode'), 'type' => 'select', 'label' => 'Razorpay mode', 'default' => $d['razorpay_mode'], 'options' => $mode,
            'show_if' => $k('razorpay_enabled') . '=1'];
        $fields[] = ['key' => $k('razorpay_title'), 'type' => 'text', 'label' => 'Name at checkout', 'default' => $d['razorpay_title'], 'show_if' => $k('razorpay_enabled') . '=1'];
        foreach (['test', 'live'] as $m) {
            $fields[] = ['key' => $k("razorpay_{$m}_key_id"), 'type' => 'text', 'label' => 'Razorpay ' . $m . ' key id', 'default' => '', 'placeholder' => "rzp_{$m}_…",
                'pattern' => "^rzp_{$m}_[A-Za-z0-9]+$", 'show_if' => $k('razorpay_enabled') . '=1',
                'help' => $m === 'test' ? 'Razorpay Dashboard → Account & Settings → API keys.' : ''];
            $fields[] = ['key' => $k("razorpay_{$m}_key_secret"), 'type' => 'secret', 'label' => 'Razorpay ' . $m . ' key secret', 'default' => '',
                'show_if' => $k('razorpay_enabled') . '=1'];
            $fields[] = ['key' => $k("razorpay_{$m}_webhook"), 'type' => 'secret', 'label' => 'Razorpay ' . $m . ' webhook secret', 'default' => '',
                'show_if' => $k('razorpay_enabled') . '=1', 'help' => 'The secret you chose when adding the webhook in Razorpay.'];
        }
        $fields[] = ['type' => 'heading', 'label' => 'Emails'];
        $fields[] = ['key' => $k('shop_email'), 'type' => 'email', 'label' => 'Send order notifications to', 'default' => '',
            'help' => 'New and paid orders are emailed here (and customers’ replies go here). Empty = the site’s admin email.'];
        return $fields;
    }

    /** Extra panel under the form: what checkout offers right now, webhook endpoints, where things are. */
    public static function panel(): void
    {
        $methods = Shop::providers();
        $row = static fn(string $name, bool $on, string $state): string => '<span>' . e($name) . '</span><span>'
            . \Slate\AdminUi::pill($state, $on ? 'success' : ($state === 'Off' ? '' : 'warn')) . '</span>';
        $s = Shop::settings();
        $stripe = !$s['stripe_enabled'] ? 'Off' : (Payments\Stripe::configured() ? 'On · ' . Payments\Stripe::mode() : 'Missing ' . Payments\Stripe::mode() . ' key');
        $razor = !$s['razorpay_enabled'] ? 'Off' : (Payments\Razorpay::configured() ? 'On · ' . Payments\Razorpay::mode() : 'Missing ' . Payments\Razorpay::mode() . ' keys');
        ?>
<section class="card">
  <div class="card-head"><h2>Payments at checkout</h2><?= $methods ? '' : \Slate\AdminUi::pill('Checkout closed', 'danger') ?></div>
  <div class="card-pad stack-sm">
    <?php if (!$methods): ?><p class="muted">No payment method is available, so customers cannot check out. Turn one on above.</p><?php endif; ?>
    <div class="kv">
      <?= $row('Manual payment', !empty($s['manual_enabled']), $s['manual_enabled'] ? 'On' : 'Off') ?>
      <?= $row('Stripe', isset($methods['stripe']), $stripe) ?>
      <?= $row('Razorpay', isset($methods['razorpay']), $razor) ?>
    </div>
  </div>
</section>
<section class="card">
  <div class="card-head"><h2>Webhooks</h2></div>
  <div class="card-pad stack-sm">
    <p class="muted small">Webhooks confirm payments even when a customer closes the window before returning to your site.</p>
    <div class="kv">
      <span>Stripe</span><code style="user-select:all"><?= e(Shop::webhookUrl('stripe')) ?></code>
      <span>Razorpay</span><code style="user-select:all"><?= e(Shop::webhookUrl('razorpay')) ?></code>
    </div>
    <p class="muted small">Stripe → Developers → Webhooks: add the URL with the events <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code>
      and <code>checkout.session.async_payment_failed</code>, then paste its signing secret above (once for test, once for live).
      Razorpay → Account &amp; Settings → Webhooks: add the URL with a secret of your choice and the events <code>order.paid</code>, <code>payment.captured</code> and
      <code>payment.failed</code>. Razorpay’s checkout script loads from checkout.razorpay.com; allow it in a strict Content-Security-Policy.</p>
  </div>
</section>
<section class="card">
  <div class="card-head"><h2>Using the shop</h2></div>
  <div class="card-pad stack-sm">
    <p>Add products under <a href="<?= e(admin_url('content/' . Product::TYPE)) ?>">Products</a>. Shop pages: <a href="<?= e(Shop::shopUrl()) ?>" target="_blank" rel="noopener">product listing</a>,
      <a href="<?= e(Shop::cartUrl()) ?>" target="_blank" rel="noopener">cart</a>, <a href="<?= e(Shop::checkoutUrl()) ?>" target="_blank" rel="noopener">checkout</a>.</p>
    <p class="muted small">Template tags: <code>{{shop:price}}</code> <code>{{shop:add_to_cart}}</code> <code>{{shop:gallery}}</code> <code>{{shop:cart_count}}</code> <code>{{shop:cart_url}}</code>.
      Theme helpers: <code>shop_price()</code>, <code>shop_add_to_cart()</code>, <code>shop_gallery()</code>, <code>shop_cart_count()</code>, <code>shop_cart_url()</code>.
      A theme can style shop pages with its own <code>templates/shop-cart.php</code>, <code>shop-checkout.php</code> and <code>shop-order.php</code> (print <code>$shop_content</code>).</p>
  </div>
</section>
        <?php
    }

    /**
     * After core saved the form ("settings_saved"): normalise values core cannot (currency case, decimals the currency
     * allows, amounts in canonical form), check key prefixes, and warn about payment methods that cannot work.
     */
    public static function saved(string $tab, string $section): void
    {
        if ($tab !== 'modules' || $section !== Admin::MODULE) {
            return;
        }
        Shop::flush();
        $errors = [];
        $notes = [];
        $currency = strtoupper((string)Shop::setting('currency'));
        Options::set(Shop::optionKey('currency'), $currency);
        $decimals = (int)Shop::setting('decimals');
        $iso = Money::isoExponent($currency);
        if ($decimals > $iso) {
            $notes[] = "$currency has $iso decimal" . ($iso === 1 ? '' : 's') . " at the payment providers, so prices use $iso.";
            Options::set(Shop::optionKey('decimals'), (string)$iso);
        }
        Shop::flush();
        foreach (['shipping_flat' => 'Flat shipping rate', 'free_shipping_over' => 'Free shipping from'] as $key => $label) {
            $raw = trim((string)Shop::setting($key));
            if ($raw === '') {
                continue;
            }
            $minor = Money::toMinor($raw);
            if ($minor === null) {
                $errors[] = "$label must be an amount such as 4.99; it was cleared.";
                Options::set(Shop::optionKey($key), '');
            } else {
                Options::set(Shop::optionKey($key), Money::toDecimal($minor));
            }
        }
        foreach (['test', 'live'] as $m) {
            $sk = Shop::secret("stripe_{$m}_secret");
            if ($sk !== '' && !preg_match('/^(sk|rk)_' . $m . '_/', $sk)) {
                $errors[] = "The Stripe $m secret key should start with sk_{$m}_ (or rk_{$m}_ for a restricted key).";
            }
            $wh = Shop::secret("stripe_{$m}_webhook");
            if ($wh !== '' && !str_starts_with($wh, 'whsec_')) {
                $errors[] = "The Stripe $m webhook signing secret should start with whsec_.";
            }
        }
        Shop::flush();
        $s = Shop::settings();
        if ($s['stripe_enabled'] && !Payments\Stripe::configured()) {
            $notes[] = 'Stripe is on but has no ' . $s['stripe_mode'] . ' secret key yet, so it is not offered at checkout.';
        }
        if ($s['razorpay_enabled'] && !Payments\Razorpay::configured()) {
            $notes[] = 'Razorpay is on but its ' . $s['razorpay_mode'] . ' keys are incomplete, so it is not offered at checkout.';
        }
        if (!Shop::providers()) {
            $notes[] = 'No payment method is available: customers cannot check out.';
        }
        Shop::ensureCacheBypass();
        if ($errors) {
            Session::flash('error', implode(' ', $errors));
        }
        if ($notes) {
            Session::flash('info', implode(' ', $notes));
        }
    }
}
