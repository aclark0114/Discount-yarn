<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/orders.php';

// The cart's contents, to notice when a started checkout no longer matches it.
function cart_fingerprint(array $lines): string
{
    return hash('sha256', json_encode(array_map(fn($l) => [(int)$l['product']['id'], $l['qty'], $l['unit']], $lines)));
}

// GET shows the payment form for the checkout started below.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $co = $_SESSION['checkout'] ?? null;
    $lines = cart_lines();
    if (!is_array($co) || !$lines || (int)$co['expires_at'] - 120 < time() || !hash_equals((string)$co['cart'], cart_fingerprint($lines))) {
        unset($_SESSION['checkout']);
        redirect('/cart.php');
    }
    page_header('Checkout', 'Pay securely for your yarn order.');
    ?>
<div class="wrap section checkout">
  <div class="section-head">
    <h1>Checkout</h1>
    <a href="/cart.php">Back to cart</a>
  </div>
  <div id="checkout" class="checkout-form" data-pk="<?= e(stripe_key('publishable_key')) ?>" data-secret="<?= e($co['secret']) ?>">
    <p class="muted" id="checkout-loading">Loading the secure payment form...</p>
  </div>
  <noscript><p class="notice">The payment form needs JavaScript. Please turn it on, or email <?= e(config('support_email')) ?> to order.</p></noscript>
  <p class="muted small">Payments are processed by Stripe. Your card details go straight to Stripe and never touch our server.</p>
</div>
<script src="https://js.stripe.com/v3/"></script>
<script src="/assets/checkout.js?v=<?= ASSET_VERSION ?>" defer></script>
<?php
    page_footer();
    exit;
}

csrf_check();

$lines = cart_lines();
if (!$lines) {
    redirect('/cart.php');
}
// Stock and prices come from Shopify: confirm them live before taking payment.
if (shopify_revalidate_lines($lines)) {
    $lines = cart_lines();
    $_SESSION['cart_adjusted'] = true;
}
if (!empty($_SESSION['cart_adjusted'])) {
    // Stock changed since the shopper last saw the cart; let them review it.
    unset($_SESSION['checkout']);
    redirect('/cart.php');
}

try {
    $order = create_pending_order($lines);
    $session = create_checkout_session($order, $lines);
} catch (Throwable $ex) {
    error_log('Checkout failed: ' . $ex->getMessage());
    $detail = !empty($_SESSION['admin']) ? ' (Shown to admins only: ' . $ex->getMessage() . ')' : '';
    flash('We could not start checkout just now. Please try again in a minute.' . $detail);
    redirect('/cart.php');
}

if (!checkout_embedded()) {
    require_once __DIR__ . '/includes/activity.php';
    activity_log('checkout', ['path' => '/checkout.php', 'value_cents' => cart_subtotal($lines)]);
    header('Location: ' . $session['url'], true, 303);
    exit;
}
require_once __DIR__ . '/includes/activity.php';
activity_log('checkout', ['path' => '/checkout.php', 'value_cents' => cart_subtotal($lines)]);

$_SESSION['checkout'] = [
    'order'      => $order['public_id'],
    'session'    => $session['id'],
    'secret'     => (string)$session['client_secret'],
    'cart'       => cart_fingerprint($lines),
    'expires_at' => (int)($session['expires_at'] ?? time() + 3600),
];
redirect('/checkout.php');
