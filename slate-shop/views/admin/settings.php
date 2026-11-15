<?php
/**
 * Shop settings (Build → Shop settings).
 */
use SlateModules\Shop\Payments\Razorpay;
use SlateModules\Shop\Payments\Stripe;
use SlateModules\Shop\Shop;

$s = Shop::settings();
$row = static function (string $label, string $control, string $help = '', string $for = ''): string {
    return '<div class="setting-row"><div class="setting-label">' . ($for !== '' ? '<label for="' . e($for) . '">' . e($label) . '</label>' : '<strong>' . e($label) . '</strong>')
        . ($help !== '' ? '<small class="help">' . e($help) . '</small>' : '') . '</div><div class="setting-control">' . $control . '</div></div>';
};
$text = static fn(string $k, string $ph = '', string $type = 'text'): string => '<input class="input" type="' . e($type) . '" id="shop_' . e($k) . '" name="' . e($k) . '" value="' . e((string)($s[$k] ?? '')) . '"' . ($ph !== '' ? ' placeholder="' . e($ph) . '"' : '') . '>';
$area = static fn(string $k, int $rows = 3): string => '<textarea class="input" id="shop_' . e($k) . '" name="' . e($k) . '" rows="' . $rows . '">' . e((string)($s[$k] ?? '')) . '</textarea>';
$toggle = static fn(string $k): string => '<input type="checkbox" class="switch" id="shop_' . e($k) . '" name="' . e($k) . '" value="1"' . (!empty($s[$k]) ? ' checked' : '') . '>';
$select = static function (string $k, array $opts) use ($s): string {
    $h = '<select class="input" id="shop_' . e($k) . '" name="' . e($k) . '">';
    foreach ($opts as $v => $l) {
        $h .= '<option value="' . e((string)$v) . '"' . ((string)($s[$k] ?? '') === (string)$v ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $h . '</select>';
};
$secret = static function (string $k): string {
    $has = Shop::hasSecret($k);
    return '<span class="input-group"><input class="input" type="password" id="shop_' . e($k) . '" name="secret[' . e($k) . ']" value="" autocomplete="new-password" placeholder="'
        . ($has ? '•••••••••••• (saved — leave empty to keep)' : 'Not set') . '">'
        . ($has ? '<label class="btn btn-ghost btn-sm"><input type="checkbox" name="clear[' . e($k) . ']" value="1"> Clear</label>' : '') . '</span>';
};
$copy = static fn(string $url): string => '<code class="secret" style="user-select:all">' . e($url) . '</code>';
?>
<form method="post" action="<?= e(admin_url('m/shop-settings/save')) ?>" class="stack">
  <?= csrf_field() ?>
  <?php if (!Shop::providers()): ?>
    <div class="banner banner-danger"><?= icon('alert', 18) ?><div>No payment method is available, so customers cannot check out. Enable at least one below.</div></div>
  <?php endif; ?>

  <section class="card">
    <div class="card-head"><h2>Store</h2></div>
    <?= $row('Currency', $text('currency', 'USD'), 'Three-letter ISO code (USD, EUR, GBP, INR…). Payment providers charge in this currency.', 'shop_currency') ?>
    <?= $row('Currency symbol', $text('currency_symbol', '$'), 'Leave empty to show the code.', 'shop_currency_symbol') ?>
    <?= $row('Symbol position', $select('currency_position', ['before' => 'Before ($10.00)', 'before_space' => 'Before with space ($ 10.00)', 'after' => 'After (10.00$)', 'after_space' => 'After with space (10.00 $)']), '', 'shop_currency_position') ?>
    <?= $row('Decimals', $select('decimals', ['0' => '0', '1' => '1', '2' => '2', '3' => '3']), 'Never more than the currency has (e.g. 0 for JPY).', 'shop_decimals') ?>
    <?= $row('Thousands separator', $text('thousand_sep', ','), '', 'shop_thousand_sep') ?>
    <?= $row('Decimal separator', $text('decimal_sep', '.'), '', 'shop_decimal_sep') ?>
    <?= $row('Notification email', $text('shop_email', (string)\Slate\Options::get('admin_email', ''), 'email'), 'New and paid orders are sent here. Empty = the site administration email.', 'shop_shop_email') ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>Shipping &amp; checkout</h2></div>
    <?= $row('Flat shipping rate', $text('shipping_flat', '0.00'), 'Added once per order. Empty = no shipping charge.', 'shop_shipping_flat') ?>
    <?= $row('Free shipping from', $text('free_shipping_over', ''), 'Orders with a subtotal of at least this amount ship free. Empty = never.', 'shop_free_shipping_over') ?>
    <?= $row('Require an address', $toggle('require_address'), 'Turn off if you only sell digital goods or services.', 'shop_require_address') ?>
    <?= $row('Require a phone number', $toggle('require_phone'), '', 'shop_require_phone') ?>
    <?= $row('After “Add to cart”', $select('after_add', ['cart' => 'Go to the cart', 'back' => 'Stay on the product page']), '', 'shop_after_add') ?>
    <?= $row('Show price & button on product pages', $toggle('auto_append'), 'Adds price, note, add to cart button and gallery below the product description. Turn off when your theme or template places them with {{shop:price}} and {{shop:add_to_cart}}.', 'shop_auto_append') ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>Manual payment</h2><?= !empty($s['manual_enabled']) ? '<span class="pill pill-success">On</span>' : '<span class="pill">Off</span>' ?></div>
    <?= $row('Offer manual payment', $toggle('manual_enabled'), 'Bank transfer, cash on delivery, invoice… Orders stay “Pending payment” until you mark them paid.', 'shop_manual_enabled') ?>
    <?= $row('Label at checkout', $text('manual_title'), '', 'shop_manual_title') ?>
    <?= $row('Payment instructions', $area('manual_instructions', 4), 'Shown on the order page and in the order email (bank details, what happens next).', 'shop_manual_instructions') ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>Stripe Checkout</h2><?= !empty($s['stripe_enabled']) ? (Stripe::configured() ? '<span class="pill pill-success">On · ' . e(Stripe::mode()) . '</span>' : '<span class="pill pill-warn">Missing key</span>') : '<span class="pill">Off</span>' ?></div>
    <?= $row('Offer Stripe', $toggle('stripe_enabled'), 'Customers pay on Stripe’s hosted payment page (cards, wallets and the methods enabled in your Stripe account).', 'shop_stripe_enabled') ?>
    <?= $row('Mode', $select('stripe_mode', ['test' => 'Test (no real money)', 'live' => 'Live']), '', 'shop_stripe_mode') ?>
    <?= $row('Label at checkout', $text('stripe_title'), '', 'shop_stripe_title') ?>
    <?= $row('Test secret key', $secret('stripe_test_secret'), 'Stripe Dashboard → Developers → API keys (test mode).', 'shop_stripe_test_secret') ?>
    <?= $row('Test webhook signing secret', $secret('stripe_test_webhook'), '', 'shop_stripe_test_webhook') ?>
    <?= $row('Live secret key', $secret('stripe_live_secret'), '', 'shop_stripe_live_secret') ?>
    <?= $row('Live webhook signing secret', $secret('stripe_live_webhook'), '', 'shop_stripe_live_webhook') ?>
    <?= $row('Webhook endpoint', $copy(Shop::webhookUrl('stripe')) . '<p class="help small">In Stripe → Developers → Webhooks, add this endpoint URL with the events <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code> and <code>checkout.session.async_payment_failed</code>, then paste its signing secret above (once for test mode, once for live).</p>') ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>Razorpay</h2><?= !empty($s['razorpay_enabled']) ? (Razorpay::configured() ? '<span class="pill pill-success">On · ' . e(Razorpay::mode()) . '</span>' : '<span class="pill pill-warn">Missing keys</span>') : '<span class="pill">Off</span>' ?></div>
    <?= $row('Offer Razorpay', $toggle('razorpay_enabled'), 'UPI, cards, netbanking and wallets through Razorpay’s checkout window.', 'shop_razorpay_enabled') ?>
    <?= $row('Mode', $select('razorpay_mode', ['test' => 'Test (no real money)', 'live' => 'Live']), '', 'shop_razorpay_mode') ?>
    <?= $row('Label at checkout', $text('razorpay_title'), '', 'shop_razorpay_title') ?>
    <?= $row('Test key id', $text('razorpay_test_key_id', 'rzp_test_…'), 'Razorpay Dashboard → Account & Settings → API keys.', 'shop_razorpay_test_key_id') ?>
    <?= $row('Test key secret', $secret('razorpay_test_key_secret'), '', 'shop_razorpay_test_key_secret') ?>
    <?= $row('Test webhook secret', $secret('razorpay_test_webhook'), '', 'shop_razorpay_test_webhook') ?>
    <?= $row('Live key id', $text('razorpay_live_key_id', 'rzp_live_…'), '', 'shop_razorpay_live_key_id') ?>
    <?= $row('Live key secret', $secret('razorpay_live_key_secret'), '', 'shop_razorpay_live_key_secret') ?>
    <?= $row('Live webhook secret', $secret('razorpay_live_webhook'), '', 'shop_razorpay_live_webhook') ?>
    <?= $row('Webhook endpoint', $copy(Shop::webhookUrl('razorpay')) . '<p class="help small">In Razorpay → Accounts &amp; Settings → Webhooks, add this URL with a secret of your choice and the events <code>order.paid</code>, <code>payment.captured</code> and <code>payment.failed</code>. Paste the same secret above. The webhook confirms payments when a customer closes the window before returning.</p>'
        . '<p class="help small">The order page loads Razorpay’s checkout script from checkout.razorpay.com; if you set a strict Content-Security-Policy, allow that host.</p>') ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>Texts</h2></div>
    <?= $row('Cart page title', $text('cart_title'), '', 'shop_cart_title') ?>
    <?= $row('Empty cart text', $area('cart_empty', 2), '', 'shop_cart_empty') ?>
    <?= $row('Checkout page title', $text('checkout_title'), '', 'shop_checkout_title') ?>
    <?= $row('Checkout introduction', $area('checkout_intro', 2), 'Optional text above the checkout form.', 'shop_checkout_intro') ?>
    <?= $row('Thank-you text', $area('thankyou_text', 2), 'Shown on the order page once the order is placed or paid.', 'shop_thankyou_text') ?>
    <?= $row('Add to cart button', $text('add_to_cart_label'), '', 'shop_add_to_cart_label') ?>
    <?= $row('Place order button', $text('order_button_label'), '', 'shop_order_button_label') ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>Using the shop</h2></div>
    <div class="card-pad stack-sm">
      <p>Add products under <a href="<?= e(admin_url('content/product')) ?>">Content → Products</a>. Shop pages: <a href="<?= e(Shop::shopUrl()) ?>" target="_blank" rel="noopener">product listing</a>,
        <a href="<?= e(Shop::cartUrl()) ?>" target="_blank" rel="noopener">cart</a>, <a href="<?= e(Shop::checkoutUrl()) ?>" target="_blank" rel="noopener">checkout</a>.</p>
      <p class="muted small">Template tags: <code>{{shop:price}}</code> <code>{{shop:add_to_cart}}</code> <code>{{shop:gallery}}</code> <code>{{shop:cart_count}}</code> <code>{{shop:cart_url}}</code>.
        Theme helpers: <code>shop_price()</code>, <code>shop_add_to_cart()</code>, <code>shop_gallery()</code>, <code>shop_cart_count()</code>, <code>shop_cart_url()</code>.
        A theme can style shop pages with its own <code>templates/shop-cart.php</code>, <code>shop-checkout.php</code> and <code>shop-order.php</code> (print <code>$shop_content</code>).</p>
    </div>
  </section>

  <p><button class="btn btn-primary">Save settings</button></p>
</form>
