<?php
require __DIR__ . '/_auth.php';
require __DIR__ . '/../includes/products.php';

$id = (int)($_GET['id'] ?? 0);
$product = null;
if ($id) {
    $st = db()->prepare('SELECT * FROM products WHERE id = ?');
    $st->execute([$id]);
    $product = $st->fetch() ?: null;
    if (!$product) {
        flash('That product no longer exists.');
        redirect('/admin/');
    }
}

$error = '';
$form = $product ? [
    'sku' => $product['sku'], 'name' => $product['name'], 'brand' => $product['brand'], 'weight' => $product['weight'],
    'fiber' => $product['fiber'], 'colorway' => $product['colorway'], 'yardage' => $product['yardage'],
    'grams' => $product['grams'], 'description' => $product['description'],
    'price' => number_format($product['price_cents'] / 100, 2, '.', ''),
    'sale_price' => $product['sale_price_cents'] ? number_format($product['sale_price_cents'] / 100, 2, '.', '') : '',
    'stock' => $product['stock'], 'is_active' => $product['is_active'], 'is_featured' => $product['is_featured'],
] : ['is_active' => 1, 'is_featured' => 0, 'stock' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['delete']) && $product) {
        // Keep the row for order history; just hide it.
        db()->prepare('UPDATE products SET is_active = 0 WHERE id = ?')->execute([$id]);
        flash('Hidden from the store. Past orders keep their details.');
        redirect('/admin/');
    }
    $form = array_merge($form, array_intersect_key($_POST, array_flip(PRODUCT_FIELDS)));
    $form['is_active'] = isset($_POST['is_active']) ? '1' : '0';
    $form['is_featured'] = isset($_POST['is_featured']) ? '1' : '0';
    try {
        $cols = product_from_input($form, $product);
        $other = product_by_sku($cols['sku']);
        if ($other && (int)$other['id'] !== $id) {
            throw new InvalidArgumentException('Another product already uses SKU ' . $cols['sku'] . '.');
        }
        $image = store_upload($_FILES['image'] ?? []);
        if ($image) {
            $cols['image'] = $image;
        } elseif (!empty($_POST['remove_image'])) {
            $cols['image'] = null;
        }
        $savedId = save_product($cols, $id ?: null);
        flash('Saved ' . $cols['name'] . '.');
        redirect('/admin/product_edit.php?id=' . $savedId);
    } catch (InvalidArgumentException | RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$v = fn(string $k) => e($form[$k] ?? '');
admin_header($product ? 'Edit ' . $product['name'] : 'Add product');
?>
<div class="head">
  <h1><?= $product ? 'Edit product' : 'Add product' ?></h1>
  <?php if ($product && $product['is_active']): ?><a href="/product.php?slug=<?= e(rawurlencode($product['slug'])) ?>" target="_blank" rel="noopener">View in store</a><?php endif; ?>
</div>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>

<form class="edit" method="post" enctype="multipart/form-data" action="/admin/product_edit.php<?= $id ? '?id=' . $id : '' ?>">
  <?= csrf_field() ?>
  <div class="cols">
    <label>Name <input name="name" value="<?= $v('name') ?>" required></label>
    <label>SKU <input name="sku" value="<?= $v('sku') ?>" required></label>
    <label>Brand <input name="brand" value="<?= $v('brand') ?>"></label>
    <label>Colorway <input name="colorway" value="<?= $v('colorway') ?>"></label>
    <label>Weight
      <select name="weight">
        <option value="">Choose</option>
        <?php foreach (YARN_WEIGHTS as $w): ?>
          <option<?= ($form['weight'] ?? '') === $w ? ' selected' : '' ?>><?= e($w) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Fiber <input name="fiber" value="<?= $v('fiber') ?>" placeholder="100% merino wool"></label>
    <label>Yardage per skein <input name="yardage" type="number" min="0" value="<?= $v('yardage') ?>"></label>
    <label>Grams per skein <input name="grams" type="number" min="0" value="<?= $v('grams') ?>"></label>
    <label>Regular price ($) <input name="price" inputmode="decimal" value="<?= $v('price') ?>" required></label>
    <label>Sale price ($) <input name="sale_price" inputmode="decimal" value="<?= $v('sale_price') ?>" placeholder="Leave blank for no sale"></label>
    <label>Stock (skeins) <input name="stock" type="number" value="<?= $v('stock') ?>"></label>
  </div>
  <label>Description <textarea name="description" rows="5"><?= $v('description') ?></textarea></label>
  <div class="image-row">
    <?php if ($product && $product['image']): ?>
      <img src="<?= e(product_image($product)) ?>" alt="" width="120" height="120">
      <label class="check"><input type="checkbox" name="remove_image" value="1"> Remove photo</label>
    <?php endif; ?>
    <label>Photo (JPG, PNG or WebP) <input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
  </div>
  <label class="check"><input type="checkbox" name="is_active" value="1"<?= !empty($form['is_active']) ? ' checked' : '' ?>> Show in store</label>
  <label class="check"><input type="checkbox" name="is_featured" value="1"<?= !empty($form['is_featured']) ? ' checked' : '' ?>> Feature on home page</label>
  <div class="actions">
    <button type="submit">Save</button>
    <?php if ($product && $product['is_active']): ?>
      <button class="danger" type="submit" name="delete" value="1" formnovalidate>Hide from store</button>
    <?php endif; ?>
  </div>
</form>
<?php admin_footer();
