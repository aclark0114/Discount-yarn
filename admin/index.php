<?php
require __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // Quick stock edits from the list.
    // Shopify products get their stock from the sync, so they are skipped.
    $upd = db()->prepare('UPDATE products SET stock = ? WHERE id = ? AND shopify_variant_id IS NULL');
    $n = 0;
    foreach ((array)($_POST['stock'] ?? []) as $id => $stock) {
        if ($stock === '' || !is_numeric($stock)) {
            continue;
        }
        $upd->execute([(int)$stock, (int)$id]);
        $n += $upd->rowCount();
    }
    flash($n . ' stock ' . ($n === 1 ? 'count' : 'counts') . ' updated.');
    redirect('/admin/' . (isset($_GET['q']) ? '?q=' . rawurlencode((string)$_GET['q']) : ''));
}

$q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$args = [];
$where = '1 = 1';
if ($q !== '') {
    $where = '(name LIKE ? OR sku LIKE ? OR brand LIKE ? OR colorway LIKE ?)';
    $like = '%' . $q . '%';
    $args = [$like, $like, $like, $like];
}
$st = db()->prepare("SELECT * FROM products WHERE $where ORDER BY is_active DESC, name, colorway");
$st->execute($args);
$products = $st->fetchAll();

$counts = db()->query('SELECT COUNT(*) AS total, SUM(is_active) AS active, SUM(CASE WHEN is_active = 1 AND stock <= 0 THEN 1 ELSE 0 END) AS sold_out FROM products')->fetch();

admin_header('Products');
?>
<div class="head">
  <h1>Products</h1>
  <p><?= (int)$counts['total'] ?> total, <?= (int)$counts['active'] ?> live, <?= (int)$counts['sold_out'] ?> sold out</p>
</div>
<form class="search" method="get" action="/admin/">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, SKU, brand, color">
  <button type="submit">Search</button>
  <a class="btn" href="/admin/product_edit.php">Add product</a>
</form>

<?php if (!$products): ?>
  <p class="empty">No products yet. <a href="/admin/product_edit.php">Add one</a> or <a href="/admin/import.php">import a CSV</a>.</p>
<?php else: ?>
<form method="post" action="/admin/<?= $q !== '' ? '?q=' . e(rawurlencode($q)) : '' ?>">
  <?= csrf_field() ?>
  <div class="table-wrap">
  <table>
    <thead><tr><th></th><th>Name</th><th>SKU</th><th>Weight</th><th>Price</th><th>Stock</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): ?>
      <tr class="<?= $p['is_active'] ? '' : 'inactive' ?>">
        <td><img src="<?= e(product_image($p, 100)) ?>" alt="" width="44" height="44" loading="lazy"></td>
        <td><a href="/admin/product_edit.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a><br><small><?= e(implode(' · ', array_filter([$p['brand'], $p['colorway']]))) ?></small></td>
        <td><?= e($p['sku']) ?></td>
        <td><?= e($p['weight']) ?></td>
        <td><?php if (percent_off($p)): ?><s><?= money((int)$p['price_cents']) ?></s> <?php endif; ?><?= money(effective_price($p)) ?></td>
        <td><?php if ($p['shopify_variant_id']): ?><span title="Synced from Shopify"><?= (int)$p['stock'] ?> <small>Shopify</small></span><?php else: ?><input class="stock-input" type="number" name="stock[<?= (int)$p['id'] ?>]" value="<?= (int)$p['stock'] ?>" aria-label="Stock for <?= e($p['name']) ?>"><?php endif; ?></td>
        <td><?= $p['is_active'] ? ((int)$p['stock'] > 0 ? 'Live' : 'Sold out') : 'Hidden' ?><?= $p['is_featured'] ? ' ★' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <button type="submit">Save stock counts</button>
</form>
<?php endif; ?>
<?php admin_footer();
