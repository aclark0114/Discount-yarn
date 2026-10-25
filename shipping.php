<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

page_header('Shipping and returns', 'Shipping rates, delivery times and our return policy.');
?>
<div class="wrap section narrow prose">
  <h1>Shipping and returns</h1>
  <h2>Shipping</h2>
  <ul>
    <li>We ship to US addresses only for now.</li>
    <li>Flat rate <?= money((int)config('shipping.flat_rate_cents')) ?>. Free on orders of <?= money((int)config('shipping.free_over_cents')) ?> or more.</li>
    <li>Orders ship within 2 business days. Delivery usually takes 3 to 7 business days after that.</li>
    <li>You'll get an email with tracking when your order ships.</li>
  </ul>
  <h2>Returns</h2>
  <p>Because our yarn is closeout and discounted, sales are final. If something arrives damaged or we sent the wrong item, email <a href="mailto:<?= e(config('support_email')) ?>"><?= e(config('support_email')) ?></a> within 14 days with your order number and a photo, and we'll make it right.</p>
  <h2>Payments</h2>
  <p>Checkout is handled by Stripe. Your card details go straight to Stripe and never touch our server.</p>
</div>
<?php page_footer();
