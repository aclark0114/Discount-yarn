<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['product_id'] ?? 0);
    $qty = (int)($_POST['qty'] ?? 0);
    if ($action === 'add' && $id > 0) {
        $st = db()->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1');
        $st->execute([$id]);
        $p = $st->fetch();
        if ($p && (int)$p['stock'] > 0) {
            $have = (int)(cart_raw()[$id] ?? 0);
            $wanted = $have + max(1, $qty);
            $new = min($wanted, (int)$p['stock']);
            cart_set($id, $new);
            if ($new > $have) {
                require_once __DIR__ . '/includes/activity.php';
                activity_log('add_to_cart', ['path' => '/product.php', 'product' => $p['group_slug'] ?: $p['slug'], 'value_cents' => effective_price($p) * ($new - $have)]);
            }
            $label = $p['name'] . ($p['colorway'] !== '' ? ' (' . $p['colorway'] . ')' : '');
            if ($new <= $have) {
                flash('You already have all ' . (int)$p['stock'] . ' of ' . $label . ' in stock in your cart.');
            } elseif ($new < $wanted) {
                flash('Only ' . (int)$p['stock'] . ' of ' . $label . ' in stock, so your cart now has ' . $new . '.');
            } else {
                flash('Added ' . $label . ' to your cart.');
            }
        }
    } elseif ($action === 'update') {
        foreach ((array)($_POST['qty'] ?? []) as $pid => $q) {
            cart_set((int)$pid, (int)$q);
        }
        flash('Cart updated.');
    } elseif ($action === 'remove' && $id > 0) {
        cart_set($id, 0);
    }
    redirect('/cart.php');
}

$lines = cart_lines();
$adjusted = !empty($_SESSION['cart_adjusted']);
unset($_SESSION['cart_adjusted']);
$subtotal = cart_subtotal($lines);
$shipping = $lines ? shipping_for($subtotal) : 0;
$freeOver = (int)config('shipping.free_over_cents', 0);

page_header('Your cart');
?>
<div class="wrap section cart">
  <h1>Your cart</h1>
  <?php if ($adjusted): ?>
    <p class="notice">Some quantities changed because stock ran low. Please check your cart.</p>
  <?php endif; ?>

  <?php if (!$lines): ?>
    <p class="empty">Your cart is empty. <a href="/shop.php">Find some yarn</a>.</p>
  <?php else: ?>
    <form method="post" action="/cart.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update">
      <ul class="cart-lines">
        <?php foreach ($lines as $l): $p = $l['product']; ?>
          <li class="cart-line">
            <img src="<?= e(product_image($p, 200)) ?>" alt="" width="96" height="96" loading="lazy">
            <div class="cart-line-info">
              <a href="/product.php?slug=<?= e(rawurlencode((string)($p['group_slug'] ?: $p['slug']))) ?>&amp;v=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a>
              <span class="muted"><?= e(implode(' · ', array_filter([$p['brand'], $p['colorway']]))) ?></span>
              <span><?= money($l['unit']) ?> each · <?= (int)$p['stock'] ?> in stock</span>
            </div>
            <label class="sr-only" for="q<?= (int)$p['id'] ?>">Quantity</label>
            <input class="qty-input" id="q<?= (int)$p['id'] ?>" type="number" name="qty[<?= (int)$p['id'] ?>]" min="0" max="<?= (int)$p['stock'] ?>" value="<?= $l['qty'] ?>">
            <strong class="cart-line-total"><?= money($l['line']) ?></strong>
            <button class="link-btn" type="submit" form="rm<?= (int)$p['id'] ?>">Remove</button>
          </li>
        <?php endforeach; ?>
      </ul>
      <button class="btn btn-ghost btn-sm" type="submit">Update cart</button>
    </form>
    <?php foreach ($lines as $l): ?>
      <form id="rm<?= (int)$l['product']['id'] ?>" method="post" action="/cart.php" hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove">
        <input type="hidden" name="product_id" value="<?= (int)$l['product']['id'] ?>">
      </form>
    <?php endforeach; ?>

    <div class="cart-summary">
      <dl>
        <dt>Subtotal</dt><dd><?= money($subtotal) ?></dd>
        <dt>Shipping</dt><dd><?= $shipping === 0 ? 'Free' : money($shipping) ?></dd>
        <dt class="total">Total</dt><dd class="total"><?= money($subtotal + $shipping) ?></dd>
      </dl>
      <?php if ($shipping > 0 && $freeOver > 0): ?>
        <p class="muted small">Add <?= money($freeOver - $subtotal) ?> more for free shipping.</p>
      <?php endif; ?>
      <form method="post" action="/checkout.php">
        <?= csrf_field() ?>
        <button class="btn btn-block" type="submit">Check out securely</button>
      </form>
      <p class="muted small">Pay securely by card on the next page. Shipping address and tax, if any, are added there.</p>
    </div>
  <?php endif; ?>
</div>
<?php page_footer();
