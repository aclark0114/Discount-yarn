<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$slug = isset($_GET['slug']) && is_string($_GET['slug']) ? $_GET['slug'] : '';
$st = db()->prepare('SELECT * FROM products WHERE slug = ? AND is_active = 1');
$st->execute([$slug]);
$p = $st->fetch();

if (!$p) {
    http_response_code(404);
    page_header('Not found');
    echo '<div class="wrap section"><h1>We couldn\'t find that yarn</h1><p>It may have sold out and been retired. <a href="/shop.php">Browse what\'s in stock</a>.</p></div>';
    page_footer();
    exit;
}

$stock = (int)$p['stock'];
$off = percent_off($p);
$inCart = (int)(cart_raw()[$p['id']] ?? 0);

$rand = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'RANDOM()' : 'RAND()';
$related = db()->prepare("SELECT * FROM products WHERE is_active = 1 AND stock > 0 AND id <> ? AND (brand = ? OR weight = ?) ORDER BY $rand LIMIT 4");
$related->execute([$p['id'], $p['brand'], $p['weight']]);
$related = $related->fetchAll();

$specs = array_filter([
    'Brand'    => $p['brand'],
    'Weight'   => $p['weight'],
    'Fiber'    => $p['fiber'],
    'Colorway' => $p['colorway'],
    'Yardage'  => $p['yardage'] ? number_format((int)$p['yardage']) . ' yd' : '',
    'Skein'    => $p['grams'] ? (int)$p['grams'] . ' g' : '',
    'SKU'      => $p['sku'],
]);

$title = $p['name'] . ($p['colorway'] ? ' in ' . $p['colorway'] : '');
page_header($title, mb_substr(strip_tags((string)$p['description']) ?: $title, 0, 155));
?>
<div class="wrap section product">
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/shop.php">Shop</a>
    <?php if ($p['weight']): ?> / <a href="/shop.php?weight=<?= e(rawurlencode($p['weight'])) ?>"><?= e($p['weight']) ?></a><?php endif; ?>
  </nav>
  <div class="product-grid">
    <div class="product-img">
      <img src="<?= e(product_image($p)) ?>" alt="<?= e($title) ?>" width="800" height="800">
      <?php if ($off > 0): ?><span class="badge"><?= $off ?>% off</span><?php endif; ?>
    </div>
    <div class="product-info">
      <?php if ($p['brand']): ?><p class="card-brand"><?= e($p['brand']) ?></p><?php endif; ?>
      <h1><?= e($p['name']) ?></h1>
      <?php if ($p['colorway']): ?><p class="colorway"><?= e($p['colorway']) ?></p><?php endif; ?>
      <p class="price price-lg">
        <?php if ($off > 0): ?><s><?= money((int)$p['price_cents']) ?></s> <?php endif; ?>
        <span><?= money(effective_price($p)) ?></span>
        <?php if ($off > 0): ?><small>You save <?= money((int)$p['price_cents'] - effective_price($p)) ?></small><?php endif; ?>
      </p>

      <?php if ($stock > 0): ?>
        <p class="stock <?= $stock <= 5 ? 'stock-low' : '' ?>"><?= $stock <= 5 ? 'Only ' . $stock . ' left' : 'In stock' ?></p>
        <form class="add-form" method="post" action="/cart.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
          <label for="qty">Skeins</label>
          <div class="qty">
            <button type="button" data-step="-1" aria-label="Fewer">&minus;</button>
            <input id="qty" name="qty" type="number" min="1" max="<?= max(1, $stock - $inCart) ?>" value="1" inputmode="numeric">
            <button type="button" data-step="1" aria-label="More">+</button>
          </div>
          <button class="btn" type="submit"<?= $inCart >= $stock ? ' disabled' : '' ?>>Add to cart</button>
        </form>
        <?php if ($inCart > 0): ?><p class="muted"><?= $inCart ?> already in your <a href="/cart.php">cart</a>.</p><?php endif; ?>
      <?php else: ?>
        <p class="stock stock-out">Sold out</p>
      <?php endif; ?>

      <?php if (trim((string)$p['description']) !== ''): ?>
        <div class="desc"><?= nl2br(e($p['description'])) ?></div>
      <?php endif; ?>

      <dl class="specs">
        <?php foreach ($specs as $k => $v): ?>
          <dt><?= e($k) ?></dt><dd><?= e($v) ?></dd>
        <?php endforeach; ?>
      </dl>
      <p class="muted small">Closeout yarn is final sale unless it arrives damaged. All skeins in one order come from the same dye lot.</p>
    </div>
  </div>
</div>

<?php if ($related): ?>
<section class="wrap section">
  <div class="section-head"><h2>You might also like</h2></div>
  <div class="grid">
    <?php foreach ($related as $r) { product_card($r); } ?>
  </div>
</section>
<?php endif; ?>

<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $title,
    'sku' => $p['sku'],
    'brand' => $p['brand'] ? ['@type' => 'Brand', 'name' => $p['brand']] : null,
    'image' => url(product_image($p)),
    'offers' => [
        '@type' => 'Offer',
        'price' => number_format(effective_price($p) / 100, 2, '.', ''),
        'priceCurrency' => 'USD',
        'availability' => $stock > 0 ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
        'url' => url('product.php?slug=' . rawurlencode($p['slug'])),
    ],
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php page_footer();
