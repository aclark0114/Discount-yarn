<?php
// Grouped catalog queries: one entry per product (a Shopify product with
// all its colorways, or a yarn added in admin), built from the per-variant
// rows in the products table.

declare(strict_types=1);

const EFFECTIVE_PRICE_SQL = 'CASE WHEN sale_price_cents > 0 AND sale_price_cents < price_cents THEN sale_price_cents ELSE price_cents END';

const GROUP_SORTS = [
    'featured'   => 'featured DESC, newest DESC',
    'new'        => 'newest DESC',
    'price-asc'  => 'min_price ASC',
    'price-desc' => 'max_price DESC',
    'name'       => 'name ASC',
    'random'     => 'RANDOM',
];

function catalog_group_count(array $where, array $args): int
{
    $st = db()->prepare('SELECT COUNT(DISTINCT group_slug) FROM products WHERE ' . implode(' AND ', $where));
    $st->execute($args);
    return (int)$st->fetchColumn();
}

// Returns groups matching $where (conditions on variant rows), each with
// totals and a representative row ('rep') used for the photo and details.
function catalog_groups(array $where, array $args, string $sort, int $limit, int $offset = 0): array
{
    $eff = EFFECTIVE_PRICE_SQL;
    $order = GROUP_SORTS[$sort] ?? GROUP_SORTS['featured'];
    if ($order === 'RANDOM') {
        $order = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'RANDOM()' : 'RAND()';
    }
    $sql = "SELECT group_slug, COUNT(*) AS variants,
                   SUM(CASE WHEN stock > 0 THEN 1 ELSE 0 END) AS in_stock,
                   MIN($eff) AS min_price, MAX($eff) AS max_price,
                   MAX(CASE WHEN $eff < price_cents THEN 100.0 * (price_cents - $eff) / price_cents ELSE 0 END) AS max_off,
                   MIN(CASE WHEN $eff < price_cents THEN 100.0 * (price_cents - $eff) / price_cents ELSE 0 END) AS min_off,
                   MAX(is_featured) AS featured, MAX(created_at) AS newest, MIN(name) AS name,
                   COALESCE(MIN(CASE WHEN stock > 0 THEN id END), MIN(id)) AS rep_id
            FROM products WHERE " . implode(' AND ', $where) . "
            GROUP BY group_slug
            ORDER BY (SUM(CASE WHEN stock > 0 THEN 1 ELSE 0 END) > 0) DESC, $order
            LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset;
    $st = db()->prepare($sql);
    $st->execute($args);
    $groups = $st->fetchAll();
    if (!$groups) {
        return [];
    }
    $ids = array_map(fn($g) => (int)$g['rep_id'], $groups);
    $reps = db()->prepare('SELECT * FROM products WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $reps->execute($ids);
    $byId = [];
    foreach ($reps->fetchAll() as $r) {
        $byId[(int)$r['id']] = $r;
    }
    foreach ($groups as &$g) {
        $g['rep'] = $byId[(int)$g['rep_id']] ?? null;
    }
    unset($g);
    return array_values(array_filter($groups, fn($g) => $g['rep'] !== null));
}

// All live colorways of one product page, in-stock first.
function catalog_group_variants(string $groupSlug): array
{
    $st = db()->prepare('SELECT * FROM products WHERE group_slug = ? AND is_active = 1 ORDER BY (stock > 0) DESC, colorway, id');
    $st->execute([$groupSlug]);
    return $st->fetchAll();
}

function group_url(string $groupSlug, ?int $variantId = null): string
{
    return '/product.php?slug=' . rawurlencode($groupSlug) . ($variantId ? '&v=' . $variantId : '');
}

function group_card(array $g): void
{
    $p = $g['rep'];
    $n = (int)$g['variants'];
    $maxOff = (int)round((float)$g['max_off']);
    $minOff = (int)round((float)$g['min_off']);
    $single = $n === 1 || (int)$g['min_price'] === (int)$g['max_price'];
    $href = group_url((string)$g['group_slug']);
    ?>
  <article class="card">
    <a class="card-img" href="<?= e($href) ?>">
      <img src="<?= e(product_image($p, 600)) ?>" alt="<?= e($p['name']) ?>" loading="lazy" width="600" height="600">
      <?php if ($maxOff > 0): ?><span class="badge"><?= $minOff === $maxOff ? '' : 'Up to ' ?><?= $maxOff ?>% off</span><?php endif; ?>
      <?php if ((int)$g['in_stock'] === 0): ?><span class="badge badge-out">Sold out</span><?php endif; ?>
    </a>
    <div class="card-body">
      <?php if ($p['brand'] !== ''): ?><p class="card-brand"><?= e($p['brand']) ?></p><?php endif; ?>
      <h3><a href="<?= e($href) ?>"><?= e($p['name']) ?></a></h3>
      <p class="card-meta"><?= e(implode(' · ', array_filter([$p['weight'], $n > 1 ? $n . ' colorways' : $p['colorway']]))) ?></p>
      <p class="price">
        <?php if ($single): ?>
          <?php if (percent_off($p) > 0): ?><s><?= money((int)$p['price_cents']) ?></s> <?php endif; ?>
          <span><?= money((int)$g['min_price']) ?></span>
        <?php else: ?>
          <span class="from">From <?= money((int)$g['min_price']) ?></span>
        <?php endif; ?>
      </p>
    </div>
  </article>
<?php
}
