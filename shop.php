<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/catalog.php';

$weightOptions = array_values(array_unique(array_merge(nav_weights(), YARN_WEIGHTS)));
$weight = isset($_GET['weight']) && in_array($_GET['weight'], $weightOptions, true) ? $_GET['weight'] : '';
$cat    = isset($_GET['cat']) && in_array($_GET['cat'], ['yarn', 'accessories'], true) ? $_GET['cat'] : '';
if ($weight !== '') {
    $cat = 'yarn';
}
$brand  = isset($_GET['brand']) && is_string($_GET['brand']) ? trim($_GET['brand']) : '';
$q      = isset($_GET['q']) && is_string($_GET['q']) ? trim(mb_substr($_GET['q'], 0, 80)) : '';
$sale   = !empty($_GET['sale']);
$instock = !empty($_GET['instock']);
$prices = ['under-10' => [0, 999], '10-20' => [1000, 1999], '20-30' => [2000, 2999], 'over-30' => [3000, PHP_INT_MAX]];
$price  = isset($_GET['price']) && isset($prices[$_GET['price']]) ? $_GET['price'] : '';
$sort = isset($_GET['sort']) && in_array($_GET['sort'], ['featured', 'new', 'price-asc', 'price-desc', 'name'], true) ? $_GET['sort'] : 'featured';

$where = ['is_active = 1'];
$args = [];
if ($cat !== '')    { $where[] = 'category = ?'; $args[] = $cat === 'accessories' ? 'accessory' : 'yarn'; }
if ($weight !== '') { $where[] = 'weight = ?'; $args[] = $weight; }
if ($brand !== '')  { $where[] = 'brand = ?';  $args[] = $brand; }
if ($sale)          { $where[] = 'sale_price_cents IS NOT NULL AND sale_price_cents > 0 AND sale_price_cents < price_cents'; }
if ($instock)       { $where[] = 'stock > 0'; }
// Bounds come from the fixed list above, so they go in as numbers (bound strings compare as text in SQLite).
if ($price !== '')  { $where[] = EFFECTIVE_PRICE_SQL . ' BETWEEN ' . (int)$prices[$price][0] . ' AND ' . (int)$prices[$price][1]; }
if ($q !== '') {
    $where[] = '(name LIKE ? OR brand LIKE ? OR colorway LIKE ? OR fiber LIKE ?)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    array_push($args, $like, $like, $like, $like);
}

$perPage = 24;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = catalog_group_count($where, $args);
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$products = catalog_groups($where, $args, $sort, $perPage, ($page - 1) * $perPage);

$brands = db()->query("SELECT DISTINCT brand FROM products WHERE is_active = 1 AND brand <> '' ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);

$heading = $sale ? 'On sale'
    : ($weight !== '' ? $weight . ' yarn'
    : ($cat === 'accessories' ? 'Accessories'
    : ($brand !== '' ? $brand
    : ($cat === 'yarn' ? 'All yarn' : 'Shop all'))));
$active = $sale ? '/shop.php?sale=1'
    : ($weight !== '' ? '/shop.php?weight=' . rawurlencode($weight)
    : ($cat !== '' ? '/shop.php?cat=' . $cat : ''));

function shop_link(array $changes): string
{
    $params = array_merge($_GET, $changes);
    unset($params['page']);
    if (isset($changes['page'])) {
        $params['page'] = $changes['page'];
    }
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null && $v !== '0');
    return '/shop.php' . ($params ? '?' . http_build_query($params) : '');
}

page_header($heading, 'Shop ' . strtolower($heading) . ' at discount prices.', $active);
?>
<div class="wrap section">
  <div class="section-head">
    <h1><?= e($heading) ?></h1>
    <?php $noun = $cat === 'yarn' ? ['yarn', 'yarns'] : ['item', 'items']; ?>
    <p class="muted"><?= $total ?> <?= $total === 1 ? $noun[0] : $noun[1] ?></p>
  </div>

  <form class="filters" method="get" action="/shop.php">
    <label class="sr-only" for="q">Search</label>
    <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= $cat === 'accessories' ? 'Search bags, books, needles' : 'Search brand, color, fiber' ?>">
    <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?= e($cat) ?>"><?php endif; ?>
    <?php if ($cat !== 'accessories'): ?>
    <label class="sr-only" for="weight">Weight</label>
    <select id="weight" name="weight">
      <option value="">Any weight</option>
      <?php foreach (nav_weights() as $w): ?>
        <option<?= $w === $weight ? ' selected' : '' ?>><?= e($w) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <label class="sr-only" for="brand">Brand</label>
    <select id="brand" name="brand">
      <option value="">Any brand</option>
      <?php foreach ($brands as $b): ?>
        <option<?= $b === $brand ? ' selected' : '' ?>><?= e($b) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="sr-only" for="sort">Sort</label>
    <select id="sort" name="sort">
      <option value="featured"<?= $sort === 'featured' ? ' selected' : '' ?>>Featured</option>
      <option value="new"<?= $sort === 'new' ? ' selected' : '' ?>>Newest</option>
      <option value="price-asc"<?= $sort === 'price-asc' ? ' selected' : '' ?>>Price, low to high</option>
      <option value="price-desc"<?= $sort === 'price-desc' ? ' selected' : '' ?>>Price, high to low</option>
      <option value="name"<?= $sort === 'name' ? ' selected' : '' ?>>Name</option>
    </select>
    <label class="sr-only" for="price">Price</label>
    <select id="price" name="price">
      <option value="">Any price</option>
      <?php foreach (['under-10' => 'Under $10', '10-20' => '$10 to $20', '20-30' => '$20 to $30', 'over-30' => '$30 and up'] as $k => $label): ?>
        <option value="<?= $k ?>"<?= $k === $price ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
    <label class="check"><input type="checkbox" name="sale" value="1"<?= $sale ? ' checked' : '' ?>> On sale</label>
    <label class="check"><input type="checkbox" name="instock" value="1"<?= $instock ? ' checked' : '' ?>> In stock</label>
    <button class="btn btn-sm" type="submit">Apply</button>
  </form>

  <?php if ($products): ?>
    <div class="grid">
      <?php foreach ($products as $g) { group_card($g); } ?>
    </div>
    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Pages">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
          <a href="<?= e(shop_link(['page' => $i])) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
        <?php endfor; ?>
      </nav>
    <?php endif; ?>
  <?php else: ?>
    <p class="empty">Nothing matches those filters. <a href="/shop.php">Clear filters</a></p>
  <?php endif; ?>
</div>
<?php page_footer();
