<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/catalog.php';

// One page per product. The slug is the group's (Shopify handle or the
// yarn's own slug); an old per-colorway slug still lands on its group.
$slug = isset($_GET['slug']) && is_string($_GET['slug']) ? $_GET['slug'] : '';
$variants = catalog_group_variants($slug);
$wanted = (int)($_GET['v'] ?? 0);
if (!$variants) {
    $st = db()->prepare('SELECT id, group_slug FROM products WHERE slug = ? AND is_active = 1');
    $st->execute([$slug]);
    if ($row = $st->fetch()) {
        $slug = (string)$row['group_slug'];
        $wanted = $wanted ?: (int)$row['id'];
        $variants = catalog_group_variants($slug);
    }
}

// An old "tov-retired-colors" link: those colorways now sit on the regular
// product's page (see shopify_group_slug()), so send it there.
if (!$variants && preg_match('/^(.+?)-retired-colou?rs?(?:-\d+)?$/', $slug, $m)) {
    foreach (db()->query('SELECT name, group_slug FROM products WHERE is_active = 1 ORDER BY id') as $row) {
        if (slugify((string)$row['name']) === $m[1] && (string)$row['group_slug'] !== '') {
            header('Location: ' . group_url((string)$row['group_slug']), true, 301);
            exit;
        }
    }
}

if (!$variants) {
    http_response_code(404);
    page_header('Not found');
    echo '<div class="wrap section"><h1>We couldn\'t find that yarn</h1><p>It may have sold out and been retired. <a href="/shop.php">Browse what\'s in stock</a>.</p></div>';
    page_footer();
    exit;
}

// Selected colorway: the one asked for if it's in stock, else the first in stock.
$p = $variants[0];
foreach ($variants as $v) {
    if ((int)$v['id'] === $wanted && ((int)$v['stock'] > 0 || (int)$p['stock'] <= 0)) {
        $p = $v;
        break;
    }
}
$cart = cart_raw();
$isYarn = ($p['category'] ?? 'yarn') !== 'accessory';

// "12 in stock", or how many are left after what's already in the cart.
function stock_note(int $stock, int $inCart): string
{
    if ($stock <= 0) {
        return 'Sold out';
    }
    if ($inCart <= 0) {
        return $stock . ' in stock';
    }
    $left = $stock - $inCart;
    return $left > 0
        ? $stock . ' in stock, ' . $inCart . ' already in your cart'
        : 'All ' . $stock . ' in stock are in your cart';
}
$multi = count($variants) > 1;
$anyInStock = (int)$variants[0]['stock'] > 0;
$stock = (int)$p['stock'];
$off = percent_off($p);
$inCart = (int)($cart[$p['id']] ?? 0);

// Every Shopify photo of this product, for the gallery under the main photo.
// A page can hold more than one Shopify product ("Tov" and "Tov (Retired
// Colors)"), so photos come from each, the selected colorway's first.
$gallery = [];
$shopifyIds = array_values(array_unique(array_filter(array_map(fn($v) => (string)$v['shopify_product_id'], [$p, ...$variants]))));
if ($shopifyIds) {
    $st = db()->prepare('SELECT url, alt_text FROM product_images WHERE shopify_product_id = ? ORDER BY position');
    $seenUrls = [];
    foreach ($shopifyIds as $sid) {
        $st->execute([$sid]);
        foreach ($st->fetchAll() as $img) {
            if (!isset($seenUrls[$img['url']])) {
                $seenUrls[$img['url']] = true;
                $gallery[] = $img;
            }
        }
    }
}

// The description shown: the fullest one on the page, so it doesn't change
// with the colorway that happens to load first.
$description = (string)$p['description'];
foreach ($variants as $v) {
    if (mb_strlen(trim((string)$v['description'])) > mb_strlen(trim($description))) {
        $description = (string)$v['description'];
    }
}

$related = catalog_groups(['is_active = 1', 'stock > 0', 'group_slug <> ?', '(brand = ? OR weight = ?)'], [$slug, $p['brand'], $p['weight']], 'random', 4);

$specs = array_filter([
    'Brand'    => $p['brand'],
    'Weight'   => $p['weight'],
    'Fiber'    => $p['fiber'],
    'Yardage'  => $p['yardage'] ? number_format((int)$p['yardage']) . ' yd' : '',
    'Skein'    => $p['grams'] ? (int)$p['grams'] . ' g' : '',
]);

$prices = array_map('effective_price', $variants);
$canonical = url(group_url($slug));
page_header($p['name'], mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags($description))) ?:$p['name'] . ' yarn at discount prices.', 0, 155), '', $canonical);
?>
<div class="wrap section product">
  <nav class="crumbs" aria-label="Breadcrumb"><a href="/shop.php">Shop</a>
    <?php if ($p['weight']): ?> / <a href="/shop.php?weight=<?= e(rawurlencode($p['weight'])) ?>"><?= e($p['weight']) ?></a><?php endif; ?>
  </nav>
  <div class="product-grid">
    <div class="product-media">
      <div class="product-img">
        <img id="main-img" src="<?= e(product_image($p)) ?>" alt="<?= e($p['name'] . ($p['colorway'] ? ', ' . $p['colorway'] : '')) ?>" width="800" height="800">
        <span class="badge" id="off-badge"<?= $off > 0 ? '' : ' hidden' ?>><?= $off ?>% off</span>
      </div>
      <?php if (count($gallery) > 1): ?>
        <ul class="thumbs" aria-label="More photos">
          <?php foreach ($gallery as $i => $img): ?>
            <li><a href="<?= e(sized_image($img['url'], 1600)) ?>" data-full="<?= e(sized_image($img['url'], 800)) ?>" data-alt="<?= e($img['alt_text'] ?: $p['name']) ?>" target="_blank" rel="noopener">
              <img src="<?= e(sized_image($img['url'], 160)) ?>" alt="<?= e($img['alt_text'] ?: $p['name'] . ' photo ' . ($i + 1)) ?>" width="80" height="80" loading="lazy">
            </a></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div class="product-info">
      <?php if ($p['brand']): ?><p class="card-brand"><?= e($p['brand']) ?></p><?php endif; ?>
      <h1><?= e($p['name']) ?></h1>
      <p class="colorway" id="colorway-name"<?= $p['colorway'] ? '' : ' hidden' ?>><?= e($p['colorway']) ?></p>
      <p class="price price-lg">
        <s id="price-was"<?= $off > 0 ? '' : ' hidden' ?>><?= money((int)$p['price_cents']) ?></s>
        <span id="price-now"><?= money(effective_price($p)) ?></span>
        <small id="price-save"<?= $off > 0 ? '' : ' hidden' ?>>You save <?= money((int)$p['price_cents'] - effective_price($p)) ?></small>
      </p>

      <?php if ($anyInStock): ?>
        <form class="add-form" method="post" action="/cart.php" id="add-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add">
          <?php if ($multi): ?>
            <fieldset class="swatches">
              <legend><?= $isYarn ? 'Colorway' : 'Option' ?> <span class="muted">(<?= count($variants) ?>)</span></legend>
              <?php foreach ($variants as $v):
                  $vs = (int)$v['stock'];
                  $vc = (int)($cart[$v['id']] ?? 0); ?>
                <label class="swatch<?= $vs <= 0 ? ' swatch-out' : '' ?>"
                  data-name="<?= e($v['colorway'] ?: $v['name']) ?>"
                  data-image="<?= e(product_image($v)) ?>"
                  data-now="<?= e(money(effective_price($v))) ?>"
                  data-was="<?= percent_off($v) > 0 ? e(money((int)$v['price_cents'])) : '' ?>"
                  data-save="<?= percent_off($v) > 0 ? e(money((int)$v['price_cents'] - effective_price($v))) : '' ?>"
                  data-off="<?= percent_off($v) ?>"
                  data-stock="<?= $vs ?>" data-incart="<?= $vc ?>" data-sku="<?= e($v['sku']) ?>">
                  <input type="radio" name="product_id" value="<?= (int)$v['id'] ?>"<?= (int)$v['id'] === (int)$p['id'] ? ' checked' : '' ?><?= $vs <= 0 ? ' disabled' : '' ?>>
                  <img src="<?= e(product_image($v, 120)) ?>" alt="" width="56" height="56" loading="lazy">
                  <span class="swatch-name"><?= e($v['colorway'] ?: 'Standard') ?></span>
                  <span class="swatch-note<?= $vs > 5 ? ' swatch-plenty' : '' ?>"><?= $vs <= 0 ? 'Sold out' : $vs . ' in stock' ?></span>
                </label>
              <?php endforeach; ?>
            </fieldset>
          <?php else: ?>
            <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
          <?php endif; ?>
          <p class="stock <?= $stock <= 5 ? 'stock-low' : '' ?>" id="stock-note"><?= e(stock_note($stock, $inCart)) ?></p>
          <label for="qty"><?= $isYarn ? 'Skeins' : 'Quantity' ?></label>
          <div class="qty">
            <button type="button" data-step="-1" aria-label="Fewer">&minus;</button>
            <input id="qty" name="qty" type="number" min="1" max="<?= max(1, $stock - $inCart) ?>" value="1" inputmode="numeric">
            <button type="button" data-step="1" aria-label="More">+</button>
          </div>
          <button class="btn" type="submit" id="add-btn"<?= $inCart >= $stock ? ' disabled' : '' ?>><?= $inCart >= $stock ? 'All in your cart' : 'Add to cart' ?></button>
        </form>
        <p class="muted" id="in-cart"<?= $inCart > 0 ? '' : ' hidden' ?>><span><?= $inCart ?></span> already in your <a href="/cart.php">cart</a>.</p>
      <?php else: ?>
        <p class="stock stock-out">Sold out</p>
      <?php endif; ?>

      <?php if (trim($description) !== ''): ?>
        <div class="desc"><?= nl2br(e($description)) ?></div>
      <?php endif; ?>

      <dl class="specs">
        <?php foreach ($specs as $k => $v): ?>
          <dt><?= e($k) ?></dt><dd><?= e($v) ?></dd>
        <?php endforeach; ?>
        <dt>SKU</dt><dd id="sku"><?= e($p['sku']) ?></dd>
      </dl>
      <p class="muted small"><?= $isYarn ? 'Discounted yarn is' : 'Discounted items are' ?> final sale. <a href="/shipping.php">Shipping and returns</a></p>
    </div>
  </div>
</div>

<?php if ($related): ?>
<section class="wrap section">
  <div class="section-head"><h2>You might also like</h2></div>
  <div class="grid">
    <?php foreach ($related as $g) { group_card($g); } ?>
  </div>
</section>
<?php endif; ?>

<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $p['name'],
    'brand' => $p['brand'] ? ['@type' => 'Brand', 'name' => $p['brand']] : null,
    'image' => str_starts_with(product_image($p), 'http') ? product_image($p) : url(product_image($p)),
    'offers' => [
        '@type' => 'AggregateOffer',
        'lowPrice' => number_format(min($prices) / 100, 2, '.', ''),
        'highPrice' => number_format(max($prices) / 100, 2, '.', ''),
        'offerCount' => count($variants),
        'priceCurrency' => 'USD',
        'availability' => $anyInStock ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
        'url' => $canonical,
    ],
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php page_footer();
