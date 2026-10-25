<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/cart.php';
require __DIR__ . '/includes/orders.php';
require __DIR__ . '/includes/shopify_sync.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/cart.php');
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
    redirect('/cart.php');
}

try {
    $order = create_pending_order($lines);
    $session = create_checkout_session($order, $lines);
} catch (Throwable $ex) {
    error_log('Checkout failed: ' . $ex->getMessage());
    flash('We could not start checkout just now. Please try again in a minute.');
    redirect('/cart.php');
}

header('Location: ' . $session['url'], true, 303);
exit;
