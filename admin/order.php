<?php
require __DIR__ . '/_auth.php';
require __DIR__ . '/../includes/orders.php';

$id = (int)($_GET['id'] ?? 0);
$order = order_by_id($id);
if (!$order) {
    flash('Order not found.');
    redirect('/admin/orders.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['push_stock'])) {
        flash(shopify_push_order_inventory($id)
            ? 'Stock subtracted in Shopify.'
            : 'Shopify stock not updated: ' . ((string)(order_by_id($id)['inventory_push_error'] ?? '') ?: 'nothing to push.'));
        redirect('/admin/order.php?id=' . $id);
    }
    $notes = trim((string)($_POST['admin_notes'] ?? ''));
    $tracking = trim((string)($_POST['tracking_number'] ?? ''));
    if (isset($_POST['ship']) && $order['status'] === 'paid') {
        db()->prepare("UPDATE orders SET status = 'shipped', shipped_at = ?, tracking_number = ?, admin_notes = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $tracking, $notes, $id]);
        $sent = '';
        if (!empty($_POST['notify'])) {
            $sent = send_shipping_notice(order_by_id($id))
                ? ' Shipping email sent.'
                : ' The shipping email did not send. Check the Postmark settings and error_log.';
        }
        flash('Marked as shipped.' . $sent);
    } else {
        db()->prepare('UPDATE orders SET tracking_number = ?, admin_notes = ? WHERE id = ?')->execute([$tracking, $notes, $id]);
        flash('Saved.');
    }
    redirect('/admin/order.php?id=' . $id);
}

$items = order_items_for($id);
admin_header('Order #' . strtoupper($order['public_id']));
?>
<div class="head">
  <h1>Order #<?= e(strtoupper($order['public_id'])) ?></h1>
  <p><?= e(ucfirst($order['status'])) ?><?= $order['paid_at'] ? ', paid ' . e(date('M j, Y g:ia', strtotime($order['paid_at']))) : '' ?></p>
</div>
<?php if (in_array($order['status'], ['paid', 'shipped'], true)): ?>
  <?php if ($order['inventory_pushed_at']): ?>
    <p class="notice">Stock subtracted in Shopify <?= e(date('M j, g:ia', strtotime($order['inventory_pushed_at']))) ?>.</p>
  <?php else: ?>
    <form method="post" action="/admin/order.php?id=<?= $id ?>" class="error">
      <?= csrf_field() ?>
      Not yet subtracted in Shopify<?= $order['inventory_push_error'] ? ': ' . e($order['inventory_push_error']) : '' ?>.
      These skeins are held back on this site until it works. The sync cron retries every 15 minutes.
      <button type="submit" name="push_stock" value="1">Retry now</button>
    </form>
  <?php endif; ?>
<?php endif; ?>
<?php if ($order['oversold']): ?>
  <p class="error">At least one item sold more than was in stock. Check the shelf before packing, and refund the short item in Stripe if needed.</p>
<?php endif; ?>

<div class="order-cols">
  <section>
    <h2>Items</h2>
    <table>
      <thead><tr><th>Item</th><th>SKU</th><th>Qty</th><th>Each</th><th>Total</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr><td><?= e($it['name']) ?></td><td><?= e($it['sku']) ?></td><td><?= (int)$it['quantity'] ?></td>
          <td><?= money((int)$it['unit_price_cents']) ?></td><td><?= money((int)$it['unit_price_cents'] * (int)$it['quantity']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="4">Subtotal</td><td><?= money((int)$order['subtotal_cents']) ?></td></tr>
        <tr><td colspan="4">Shipping</td><td><?= money((int)$order['shipping_cents']) ?></td></tr>
        <?php if ((int)$order['tax_cents']): ?><tr><td colspan="4">Tax</td><td><?= money((int)$order['tax_cents']) ?></td></tr><?php endif; ?>
        <tr><th colspan="4">Total</th><th><?= money((int)$order['total_cents']) ?></th></tr>
      </tfoot>
    </table>
  </section>
  <section>
    <h2>Ship to</h2>
    <p class="address"><?= nl2br(e($order['shipping_address'] ?: 'Not provided yet')) ?></p>
    <p><?= e($order['email']) ?></p>
    <?php if ($order['stripe_payment_intent']): ?>
      <p><a href="https://dashboard.stripe.com/payments/<?= e(rawurlencode($order['stripe_payment_intent'])) ?>" target="_blank" rel="noopener">Open payment in Stripe</a></p>
    <?php endif; ?>
  </section>
</div>

<form class="edit" method="post" action="/admin/order.php?id=<?= $id ?>">
  <?= csrf_field() ?>
  <label>Tracking number <input name="tracking_number" value="<?= e($order['tracking_number']) ?>"></label>
  <label>Notes (only you see these) <textarea name="admin_notes" rows="3"><?= e($order['admin_notes']) ?></textarea></label>
  <div class="actions">
    <button type="submit">Save</button>
    <?php if ($order['status'] === 'paid'): ?>
      <label class="check"><input type="checkbox" name="notify" value="1" checked> Email the customer</label>
      <button type="submit" name="ship" value="1">Mark as shipped</button>
    <?php endif; ?>
  </div>
</form>
<?php admin_footer();
