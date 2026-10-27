<?php
// Every product's photos on one page, to look for things the text search
// can't see (a logo, a shop bag or a price tag in the picture), with a
// Hide / Show button per product.
require __DIR__ . '/_auth.php';
require_once __DIR__ . '/../includes/products.php';

const PHOTOS_PER_PAGE = 30;

$show = in_array($_GET['show'] ?? '', ['showing', 'hidden'], true) ? $_GET['show'] : 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$here = '/admin/photos.php?' . http_build_query(['show' => $show, 'page' => $page]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!products_have_hide_columns()) {
        flash('Run schema_v9_hide.sql in phpMyAdmin first.');
    } elseif (preg_match('/^(hide|show):(.+)$/', (string)($_POST['action'] ?? ''), $m)) {
        products_set_hidden([$m[2]], $m[1] === 'hide');
        flash(($m[1] === 'hide' ? 'Hid ' : 'Put back ') . ($_POST['name'] ?? 'the product') . '.');
    }
    redirect($here . '#p-' . rawurlencode((string)($m[2] ?? '')));
}

$hasHide = products_have_hide_columns();
$groups = [];
foreach (db()->query('SELECT * FROM products ORDER BY name, colorway, id') as $p) {
    $key = (string)($p['group_slug'] ?: $p['slug']);
    $g = &$groups[$key];
    $g ??= ['slug' => $key, 'rep' => $p, 'photos' => [], 'hidden' => false, 'live' => false];
    $g['hidden'] = $g['hidden'] || !empty($p['admin_hidden']);
    $g['live'] = $g['live'] || (int)$p['is_active'] === 1;
    if (!empty($p['image_url']) || !empty($p['image'])) {
        $g['photos'][product_image($p, 1600)] = [product_image($p, 240), $p['colorway'] !== '' ? $p['colorway'] : 'Main photo'];
    }
    if ((string)$p['shopify_product_id'] !== '') {
        $g['shopify'][(string)$p['shopify_product_id']] = true;   // a page can hold more than one Shopify product
    }
    unset($g);
}
// Shopify gallery photos, keyed by Shopify product.
$byShopify = [];
foreach ($groups as $key => $g) {
    foreach (array_keys($g['shopify'] ?? []) as $sid) {
        $byShopify[(string)$sid] = $key;
    }
}
try {
    foreach (db()->query('SELECT shopify_product_id, url, alt_text FROM product_images ORDER BY shopify_product_id, position') as $img) {
        $key = $byShopify[(string)$img['shopify_product_id']] ?? null;
        if ($key !== null) {
            $groups[$key]['photos'][sized_image((string)$img['url'], 1600)] ??= [sized_image((string)$img['url'], 240), (string)$img['alt_text']];
        }
    }
} catch (PDOException $ex) {
    // No gallery table yet (schema_v4_images.sql not run): variant photos only.
}

$groups = array_filter($groups, fn($g) => $show === 'all' || ($show === 'hidden') === $g['hidden']);
$total = count($groups);
$photoCount = array_sum(array_map(fn($g) => count($g['photos']), $groups));
$pages = max(1, (int)ceil($total / PHOTOS_PER_PAGE));
$page = min($page, $pages);
$list = array_slice($groups, ($page - 1) * PHOTOS_PER_PAGE, PHOTOS_PER_PAGE);
$link = fn(array $q) => '/admin/photos.php?' . http_build_query($q + ['show' => $show, 'page' => 1]);

admin_header('Review photos');
?>
<h1>Review photos</h1>
<p>Every photo of every product, <?= PHOTOS_PER_PAGE ?> products per page. Look for anything that shouldn't be on Discount Yarn, such as a Stars Hollow logo, shop bag or price tag, and hide that product. Click a photo to open it full size.</p>
<?php if (!$hasHide): ?><p class="error">Run <code>schema_v9_hide.sql</code> in phpMyAdmin to turn on the Hide buttons.</p><?php endif; ?>
<nav class="tabs">
  <?php foreach (['all' => 'All products', 'showing' => 'Showing', 'hidden' => 'Hidden by you'] as $k => $label): ?>
    <a href="<?= e($link(['show' => $k])) ?>"<?= $show === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<p><?= $total ?> products, <?= $photoCount ?> photos. Page <?= $page ?> of <?= $pages ?>.</p>

<?php if (!$list): ?>
  <p class="empty">No products here.</p>
<?php else: ?>
<div class="photo-review">
<?php foreach ($list as $g): $p = $g['rep']; ?>
  <section class="photo-card<?= $g['hidden'] ? ' is-hidden' : '' ?>" id="p-<?= e(rawurlencode($g['slug'])) ?>">
    <div class="photo-head">
      <div>
        <a href="/admin/product_edit.php?id=<?= (int)$p['id'] ?>"><strong><?= e($p['name']) ?></strong></a>
        <small><?= e(implode(' · ', array_filter([$p['brand'], count($g['photos']) . ' ' . (count($g['photos']) === 1 ? 'photo' : 'photos')]))) ?></small>
      </div>
      <?php if ($hasHide): ?>
      <form method="post" action="<?= e($here) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="name" value="<?= e($p['name']) ?>">
        <span class="photo-status"><?= $g['hidden'] ? 'Hidden by you' : ($g['live'] ? 'Showing' : 'Not live') ?></span>
        <?php if ($g['hidden']): ?><button type="submit" name="action" value="show:<?= e($g['slug']) ?>">Show</button><?php else: ?><button type="submit" name="action" value="hide:<?= e($g['slug']) ?>" class="danger">Hide</button><?php endif; ?>
      </form>
      <?php endif; ?>
    </div>
    <div class="photo-strip">
      <?php foreach ($g['photos'] as $full => [$thumb, $alt]): ?>
        <a href="<?= e($full) ?>" target="_blank" rel="noopener"><img src="<?= e($thumb) ?>" alt="<?= e($alt) ?>" title="<?= e($alt) ?>" width="120" height="120" loading="lazy"></a>
      <?php endforeach; ?>
      <?php if (!$g['photos']): ?><span class="muted-note">No photos.</span><?php endif; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>
<?php if ($pages > 1): ?>
<nav class="tabs pager">
  <?php if ($page > 1): ?><a href="<?= e($link(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
  <?php for ($i = 1; $i <= $pages; $i++): ?><a href="<?= e($link(['page' => $i])) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a><?php endfor; ?>
  <?php if ($page < $pages): ?><a href="<?= e($link(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php admin_footer();
