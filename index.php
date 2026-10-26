<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/catalog.php';

$featured = catalog_groups(['is_active = 1', 'stock > 0'], [], 'featured', 8);

$weights = db()->query(
    "SELECT weight, COUNT(DISTINCT group_slug) AS n FROM products WHERE is_active = 1 AND stock > 0 AND category = 'yarn' AND weight <> '' GROUP BY weight"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$inStock = (int)db()->query("SELECT COUNT(DISTINCT group_slug) FROM products WHERE is_active = 1 AND stock > 0 AND category = 'yarn'")->fetchColumn();

// Photos for the top of the page: yarn only (never books or bags), one per
// weight with the best-stocked weights first, 4 in total.
$heroWhere = ['is_active = 1', 'stock > 0', "category = 'yarn'", '(image_url IS NOT NULL OR image IS NOT NULL)'];
$heroPhotos = [];
$byWeight = db()->query(
    "SELECT weight FROM products WHERE is_active = 1 AND stock > 0 AND category = 'yarn' AND weight <> ''
     AND (image_url IS NOT NULL OR image IS NOT NULL) GROUP BY weight ORDER BY COUNT(DISTINCT group_slug) DESC"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($byWeight as $w) {
    if (count($heroPhotos) >= 4) {
        break;
    }
    $heroPhotos = array_merge($heroPhotos, catalog_groups([...$heroWhere, 'weight = ?'], [$w], 'featured', 1));
}
// Fewer than 4 weights in stock: fill up with other yarn.
if (count($heroPhotos) < 4) {
    $taken = array_map(fn($g) => (string)$g['group_slug'], $heroPhotos);
    $more = catalog_groups($heroWhere, [], 'featured', 4 + count($taken));
    foreach ($more as $g) {
        if (count($heroPhotos) < 4 && !in_array((string)$g['group_slug'], $taken, true)) {
            $heroPhotos[] = $g;
        }
    }
}

$brands = db()->query(
    "SELECT brand, COUNT(DISTINCT group_slug) AS n FROM products WHERE is_active = 1 AND stock > 0 AND brand <> ''
     GROUP BY brand ORDER BY n DESC, brand LIMIT 12"
)->fetchAll();

page_header('');
?>
<section class="hero">
  <div class="wrap hero-inner">
    <div class="hero-copy">
      <h1>Name-brand yarn, marked down.</h1>
      <p class="lede"><?= $inStock > 0 ? number_format($inStock) . ' yarns in stock right now, most of them below their usual price.' : 'New yarn is being added now.' ?>
        Stock is limited, so if you love a colour, get enough for the whole project.</p>
      <div class="hero-actions">
        <a class="btn" href="/shop.php">Shop all yarn</a>
        <a class="btn btn-ghost" href="/shop.php?sale=1">On sale</a>
      </div>
    </div>
    <?php if (count($heroPhotos) >= 2): ?>
      <div class="hero-photos count-<?= count($heroPhotos) ?>">
        <?php foreach ($heroPhotos as $g): $p = $g['rep']; ?>
          <a href="<?= e(group_url((string)$g['group_slug'])) ?>" title="<?= e($p['name'] . ($p['weight'] ? ', ' . $p['weight'] : '')) ?>">
            <img src="<?= e(product_image($p, 500)) ?>" alt="<?= e($p['name'] . ($p['brand'] ? ' by ' . $p['brand'] : '')) ?>" width="500" height="500">
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="hero-art" aria-hidden="true">
        <img src="/assets/hero-skeins.svg" alt="" width="520" height="420">
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="wrap section">
  <div class="section-head">
    <h2>In stock now</h2>
    <a href="/shop.php?sort=new">View all</a>
  </div>
  <?php if ($featured): ?>
    <div class="grid">
      <?php foreach ($featured as $g) { group_card($g); } ?>
    </div>
  <?php else: ?>
    <p class="empty">New yarn is on the way. Check back soon.</p>
  <?php endif; ?>
</section>

<?php if ($weights): ?>
<section class="band">
  <div class="wrap section">
    <h2>Shop by weight</h2>
    <ul class="weights">
      <?php foreach (nav_weights() as $w): if (empty($weights[$w])) { continue; } $n = (int)$weights[$w]; ?>
        <li><a href="/shop.php?weight=<?= e(rawurlencode($w)) ?>">
          <span class="weight-name"><?= e($w) ?></span>
          <span class="weight-count"><?= $n ?> <?= $n === 1 ? 'yarn' : 'yarns' ?></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>

<?php if ($brands): ?>
<section class="wrap section">
  <h2>Brands in stock</h2>
  <p class="brand-list">
    <?php foreach ($brands as $i => $b): ?><?= $i ? ' · ' : '' ?><a href="/shop.php?brand=<?= e(rawurlencode($b['brand'])) ?>"><?= e($b['brand']) ?></a><?php endforeach; ?>
  </p>
</section>
<?php endif; ?>
<?php page_footer();
