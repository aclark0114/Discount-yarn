<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/orders.php';

$publicId = isset($_GET['order']) && is_string($_GET['order']) ? $_GET['order'] : '';
$sessionId = isset($_GET['session_id']) && is_string($_GET['session_id']) ? $_GET['session_id'] : '';
$order = $publicId !== '' ? order_by_public_id($publicId) : null;

if (!$order || $sessionId === '' || !hash_equals((string)$order['stripe_session_id'], $sessionId)) {
    redirect('/');
}

// The webhook usually lands first. If it hasn't yet, ask Stripe directly.
if ($order['status'] === 'pending') {
    try {
        $fulfilled = fulfill_from_session(stripe_request('GET', 'checkout/sessions/' . rawurlencode($sessionId)));
        if ($fulfilled) {
            $order = $fulfilled;
        }
    } catch (Throwable $ex) {
        error_log('Success page lookup failed: ' . $ex->getMessage());
    }
}

cart_clear();
unset($_SESSION['checkout']);
$paid = in_array($order['status'], ['paid', 'shipped'], true);
$items = order_items_for((int)$order['id']);

page_header('Thank you');
?>
<div class="wrap section narrow">
  <p class="eyebrow">Order #<?= e(strtoupper($order['public_id'])) ?></p>
  <h1>Thank you<?= $order['name'] ? ', ' . e(strtok((string)$order['name'], ' ')) : '' ?>!</h1>
  <?php if ($paid): ?>
    <p class="lede">Your payment went through. A confirmation is on its way to <?= e($order['email']) ?>, and we'll email again when your yarn ships.</p>
  <?php else: ?>
    <p class="lede">We're confirming your payment with Stripe. You'll get an email as soon as it clears.</p>
  <?php endif; ?>
  <ul class="order-items">
    <?php foreach ($items as $it): ?>
      <li><span><?= e($it['name']) ?> &times; <?= (int)$it['quantity'] ?></span><span><?= money((int)$it['unit_price_cents'] * (int)$it['quantity']) ?></span></li>
    <?php endforeach; ?>
    <li class="total"><span>Total</span><span><?= money((int)$order['total_cents']) ?></span></li>
  </ul>
  <a class="btn" href="/shop.php">Keep shopping</a>
</div>
<?php page_footer();
