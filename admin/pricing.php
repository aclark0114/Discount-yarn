<?php
require __DIR__ . '/_auth.php';
require __DIR__ . '/../includes/pricing.php';

$buckets = discount_buckets();
$parse = function ($v) use ($buckets): ?int {
    // '' means "inherit" (no rule); anything else must be a configured tier.
    if ($v === '' || $v === null) {
        return null;
    }
    $v = (int)$v;
    if (!in_array($v, $buckets, true)) {
        throw new InvalidArgumentException('Pick one of the configured tiers.');
    }
    return $v;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $pdo = db();
        $pdo->beginTransaction();
        if (isset($_POST['default'])) {
            set_price_rule('default', '', $parse($_POST['default']) ?? 0);
        }
        foreach ((array)($_POST['brand'] ?? []) as $brand => $v) {
            set_price_rule('brand', (string)$brand, $parse($v));
        }
        foreach ((array)($_POST['product'] ?? []) as $pid => $v) {
            set_price_rule('product', (string)$pid, $parse($v));
        }
        $n = reprice_shopify_rows();
        $pdo->commit();
        flash('Tiers saved. ' . $n . ' ' . ($n === 1 ? 'price' : 'prices') . ' changed.');
    } catch (InvalidArgumentException $ex) {
        db()->rollBack();
        flash($ex->getMessage());
    }
    redirect('/admin/pricing.php' . (isset($_GET['q']) ? '?q=' . rawurlencode((string)$_GET['q']) : ''));
}

$rules = price_rules();
$default = (int)($rules['default'][''] ?? 0);
$brands = db()->query(
    "SELECT brand, COUNT(DISTINCT shopify_product_id) AS n FROM products
     WHERE shopify_variant_id IS NOT NULL AND is_active = 1 AND brand <> '' GROUP BY brand ORDER BY brand"
)->fetchAll();

$q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$groups = [];
if ($q !== '') {
    $st = db()->prepare(
        "SELECT shopify_product_id, MIN(name) AS name, MIN(brand) AS brand, COUNT(*) AS variants,
                MIN(shopify_price_cents) AS min_price, MAX(shopify_price_cents) AS max_price
         FROM products WHERE shopify_variant_id IS NOT NULL AND is_active = 1 AND (name LIKE ? OR brand LIKE ?)
         GROUP BY shopify_product_id ORDER BY MIN(name) LIMIT 60"
    );
    $st->execute(['%' . $q . '%', '%' . $q . '%']);
    $groups = $st->fetchAll();
}

$select = function (string $name, ?int $current, string $inheritLabel) use ($buckets): string {
    $html = '<select name="' . e($name) . '">';
    if ($inheritLabel !== '') {
        $html .= '<option value=""' . ($current === null ? ' selected' : '') . '>' . e($inheritLabel) . '</option>';
    }
    foreach ($buckets as $b) {
        $html .= '<option value="' . $b . '"' . ($current === $b ? ' selected' : '') . '>' . ($b === 0 ? 'No discount' : $b . '% off') . '</option>';
    }
    return $html . '</select>';
};

admin_header('Pricing');
?>
<div class="head"><h1>Discount tiers</h1></div>
<p>Yarn from Shopify sells at Shopify's price less its tier. A product's own tier wins, then its brand's, then the store default. The tiers offered are set by <code>discount_buckets</code> in the config (now <?= e(implode(', ', array_map(fn($b) => $b . '%', $buckets))) ?>). Changes apply immediately.</p>

<form class="edit" method="post" action="/admin/pricing.php<?= $q !== '' ? '?q=' . e(rawurlencode($q)) : '' ?>">
  <?= csrf_field() ?>
  <label>Store default <?= $select('default', $default, '') ?></label>

  <h2>By brand</h2>
  <?php if (!$brands): ?>
    <p class="empty">No Shopify yarn synced yet.</p>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Brand</th><th>Products</th><th>Tier</th></tr></thead>
      <tbody>
      <?php foreach ($brands as $b): ?>
        <tr><td><?= e($b['brand']) ?></td><td><?= (int)$b['n'] ?></td>
          <td><?= $select('brand[' . $b['brand'] . ']', $rules['brand'][$b['brand']] ?? null, 'Store default (' . $default . '%)') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if ($groups): ?>
  <h2>Products matching "<?= e($q) ?>"</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Product</th><th>Colorways</th><th>Shopify price</th><th>Tier</th></tr></thead>
      <tbody>
      <?php foreach ($groups as $g): ?>
        <tr><td><?= e($g['name']) ?><br><small><?= e($g['brand']) ?></small></td><td><?= (int)$g['variants'] ?></td>
          <td><?= money((int)$g['min_price']) ?><?= $g['max_price'] !== $g['min_price'] ? ' to ' . money((int)$g['max_price']) : '' ?></td>
          <td><?= $select('product[' . $g['shopify_product_id'] . ']', $rules['product'][$g['shopify_product_id']] ?? null, 'Brand or default') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php elseif ($q !== ''): ?>
    <p class="empty">No Shopify products match "<?= e($q) ?>".</p>
  <?php endif; ?>

  <div class="actions"><button type="submit">Save tiers</button></div>
</form>

<form class="search" method="get" action="/admin/pricing.php">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Find a product to give it its own tier">
  <button type="submit">Find</button>
</form>
<?php if ($rules['product']): ?>
  <p><?= count($rules['product']) ?> <?= count($rules['product']) === 1 ? 'product has' : 'products have' ?> their own tier.</p>
<?php endif; ?>
<?php admin_footer();
