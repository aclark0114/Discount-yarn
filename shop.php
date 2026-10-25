<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$weight = isset($_GET['weight']) && in_array($_GET['weight'], YARN_WEIGHTS, true) ? $_GET['weight'] : '';
$brand  = isset($_GET['brand']) && is_string($_GET['brand']) ? trim($_GET['brand']) : '';
$q      = isset($_GET['q']) && is_string($_GET['q']) ? trim(mb_substr($_GET['q'], 0, 80)) : '';
$sale   = !empty($_GET['sale']);
$instock = !empty($_GET['instock']);
$sorts = [
    'featured'   => 'is_featured DESC, created_at DESC',
    'new'        => 'created_at DESC',
    'price-asc'  => 'COALESCE(NULLIF(sale_price_cents, 0), price_cents) ASC',
    'price-desc' => 'COALESCE(NULLIF(sale_price_cents, 0), price_cents) DESC',
    'name'       => 'name ASC',
];
$sort = isset($_GET['sort']) && isset($sorts[$_GET['sort']]) ? $_GET['sort'] : 'featured';

$where = ['is_active = 1'];
$args = [];
if ($weight !== '') { $where[] = 'weight = ?'; $args[] = $weight; }
if ($brand !== '')  { $where[] = 'brand = ?';  $args[] = $brand; }
if ($sale)          { $where[] = 'sale_price_cents IS NOT NULL AND sale_price_cents > 0 AND sale_price_cents < price_cents'; }
if ($instock)       { $where[] = 'stock > 0'; }
if ($q !== '') {
    $where[] = '(name LIKE ? OR brand LIKE ? OR colorway LIKE ? OR fiber LIKE ?)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    array_push($args, $like, $like, $like, $like);
}

$perPage = 24;
$page = max(1, (int)($_GET['page'] ?? 1));
$whereSql = implode(' AND ', $where);

$st = db()->prepare("SELECT COUNT(*) FROM products WHERE $whereSql");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$st = db()->prepare("SELECT * FROM products WHERE $whereSql ORDER BY (stock > 0) DESC, {$sorts[$sort]} LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$st->execute($args);
$products = $st->fetchAll();

$brands = db()->query("SELECT DISTINCT brand FROM products WHERE is_active = 1 AND brand <> '' ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);

$heading = $sale ? 'On sale' : ($weight !== '' ? $weight . ' yarn' : ($brand !== '' ? $brand : 'All yarn'));
$active = $sale ? '/shop.php?sale=1' : ($weight !== '' ? '/shop.php?weight=' . $weight : ($brand === '' && $q === '' ? '/shop.php' : ''));

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
    <p class="muted"><?= $total ?> <?= $total === 1 ? 'yarn' : 'yarns' ?></p>
  </div>

  <form class="filters" method="get" action="/shop.php">
    <label class="sr-only" for="q">Search</label>
    <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Search brand, color, fiber">
    <label class="sr-only" for="weight">Weight</label>
    <select id="weight" name="weight">
      <option value="">Any weight</option>
      <?php foreach (YARN_WEIGHTS as $w): ?>
        <option<?= $w === $weight ? ' selected' : '' ?>><?= e($w) ?></option>
      <?php endforeach; ?>
    </select>
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
    <label class="check"><input type="checkbox" name="sale" value="1"<?= $sale ? ' checked' : '' ?>> On sale</label>
    <label class="check"><input type="checkbox" name="instock" value="1"<?= $instock ? ' checked' : '' ?>> In stock</label>
    <button class="btn btn-sm" type="submit">Apply</button>
  </form>

  <?php if ($products): ?>
    <div class="grid">
      <?php foreach ($products as $p) { product_card($p); } ?>
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
