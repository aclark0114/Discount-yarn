<?php
require __DIR__ . '/_auth.php';

$statuses = ['paid' => 'To ship', 'shipped' => 'Shipped', 'pending' => 'Abandoned / in checkout', 'expired' => 'Expired', 'all' => 'All'];
$status = isset($_GET['status']) && isset($statuses[$_GET['status']]) ? $_GET['status'] : 'paid';

$sql = 'SELECT o.*, (SELECT SUM(quantity) FROM order_items i WHERE i.order_id = o.id) AS skeins FROM orders o';
$args = [];
if ($status !== 'all') {
    $sql .= ' WHERE o.status = ?';
    $args[] = $status;
}
$sql .= ' ORDER BY o.created_at ' . ($status === 'paid' ? 'ASC' : 'DESC') . ' LIMIT 200';
$st = db()->prepare($sql);
$st->execute($args);
$orders = $st->fetchAll();

admin_header('Orders');
?>
<div class="head"><h1>Orders</h1></div>
<nav class="tabs">
  <?php foreach ($statuses as $k => $label): ?>
    <a href="/admin/orders.php?status=<?= $k ?>"<?= $k === $status ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php if (!$orders): ?>
  <p class="empty">No orders here.</p>
<?php else: ?>
<div class="table-wrap">
<table>
  <thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Skeins</th><th>Total</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr>
      <td><a href="/admin/order.php?id=<?= (int)$o['id'] ?>">#<?= e(strtoupper($o['public_id'])) ?></a><?= $o['oversold'] ? ' <span class="flag">oversold</span>' : '' ?></td>
      <td><?= e(date('M j, g:ia', strtotime($o['paid_at'] ?? $o['created_at']))) ?></td>
      <td><?= e($o['name'] ?: 'Not given') ?><br><small><?= e($o['email']) ?></small></td>
      <td><?= (int)$o['skeins'] ?></td>
      <td><?= money((int)$o['total_cents']) ?></td>
      <td><?= e(ucfirst($o['status'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php admin_footer();
