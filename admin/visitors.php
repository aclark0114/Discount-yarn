<?php
require __DIR__ . '/_auth.php';
require __DIR__ . '/../includes/activity.php';

$ranges = [1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'];
$days = isset($_GET['days']) && isset($ranges[(int)$_GET['days']]) ? (int)$_GET['days'] : 7;
$since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

$pdo = db();
$one = function (string $sql, array $args = []) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
};
$all = function (string $sql, array $args = []) use ($pdo): array {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
};

try {
    $visitors  = (int)$one("SELECT COUNT(DISTINCT visitor) FROM site_events WHERE event = 'view' AND created_at >= ?", [$since]);
    $views     = (int)$one("SELECT COUNT(*) FROM site_events WHERE event = 'view' AND created_at >= ?", [$since]);
    $sawProd   = (int)$one("SELECT COUNT(DISTINCT visitor) FROM site_events WHERE event = 'view' AND product IS NOT NULL AND created_at >= ?", [$since]);
    $added     = (int)$one("SELECT COUNT(DISTINCT visitor) FROM site_events WHERE event = 'add_to_cart' AND created_at >= ?", [$since]);
    $checkouts = (int)$one("SELECT COUNT(DISTINCT visitor) FROM site_events WHERE event = 'checkout' AND created_at >= ?", [$since]);
    $live      = (int)$one('SELECT COUNT(DISTINCT visitor) FROM site_events WHERE created_at >= ?', [date('Y-m-d H:i:s', time() - 300)]);
    $daily     = $all("SELECT DATE(created_at) AS d, COUNT(DISTINCT visitor) AS v, COUNT(*) AS n FROM site_events
                       WHERE event = 'view' AND created_at >= ? GROUP BY DATE(created_at)", [$since]);
    $pages     = $all("SELECT path, COUNT(*) AS n, COUNT(DISTINCT visitor) AS v FROM site_events
                       WHERE event = 'view' AND product IS NULL AND created_at >= ? GROUP BY path ORDER BY n DESC LIMIT 10", [$since]);
    $products  = $all("SELECT product, SUM(CASE WHEN event = 'view' THEN 1 ELSE 0 END) AS views,
                              COUNT(DISTINCT CASE WHEN event = 'add_to_cart' THEN visitor END) AS adds
                       FROM site_events WHERE product IS NOT NULL AND created_at >= ? GROUP BY product ORDER BY views DESC LIMIT 15", [$since]);
    // Each visitor counts once, under the outside site they arrived from;
    // visitors who never arrived from another site count as direct.
    $sources   = $all("SELECT referrer_host AS src, COUNT(DISTINCT visitor) AS v FROM site_events
                       WHERE event = 'view' AND referrer_host IS NOT NULL AND created_at >= ? GROUP BY referrer_host ORDER BY v DESC LIMIT 10", [$since]);
    $referred  = (int)$one("SELECT COUNT(DISTINCT visitor) FROM site_events WHERE event = 'view' AND referrer_host IS NOT NULL AND created_at >= ?", [$since]);
    if ($visitors > $referred) {
        $sources[] = ['src' => '', 'v' => $visitors - $referred];
        usort($sources, fn($a, $b) => (int)$b['v'] <=> (int)$a['v']);
    }
    $campaigns = $all("SELECT utm_source, utm_medium, utm_campaign, COUNT(DISTINCT visitor) AS v FROM site_events
                       WHERE event = 'view' AND utm_source IS NOT NULL AND created_at >= ?
                       GROUP BY utm_source, utm_medium, utm_campaign ORDER BY v DESC LIMIT 10", [$since]);
    $devices   = $all("SELECT device, COUNT(DISTINCT visitor) AS v FROM site_events WHERE event = 'view' AND created_at >= ? GROUP BY device ORDER BY v DESC", [$since]);
    $recent    = $all('SELECT created_at, visitor, event, path, product, value_cents, referrer_host, device FROM site_events ORDER BY id DESC LIMIT 40');
    $orders    = $all("SELECT COUNT(*) AS n, COALESCE(SUM(total_cents), 0) AS total FROM orders WHERE status IN ('paid', 'shipped') AND paid_at >= ?", [$since])[0];
    $error = '';
} catch (PDOException $ex) {
    $error = 'Visitor tracking isn\'t set up yet. Run schema_v5_visitors.sql in phpMyAdmin.';
}

admin_header('Visitors');
?>
<div class="head">
  <h1>Visitors</h1>
  <nav class="tabs">
    <?php foreach ($ranges as $d => $label): ?>
      <a href="/admin/visitors.php?days=<?= $d ?>"<?= $d === $days ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
</div>

<?php if ($error): ?>
  <p class="error"><?= e($error) ?></p>
<?php else:
    // Product names for the slugs in the activity.
    $names = [];
    $slugs = array_merge(array_column($products, 'product'), array_filter(array_column($recent, 'product')));
    if ($slugs) {
        $slugs = array_values(array_unique($slugs));
        foreach ($all('SELECT group_slug, MIN(name) AS name FROM products WHERE group_slug IN (' . implode(',', array_fill(0, count($slugs), '?')) . ') GROUP BY group_slug', $slugs) as $r) {
            $names[$r['group_slug']] = $r['name'];
        }
    }
    $pname = fn($slug) => $names[$slug] ?? $slug;
    $pct = fn($a, $b) => $b > 0 ? round(100 * $a / $b) . '%' : '';

    // Every day in the range, including days with no visits.
    $byDay = [];
    foreach ($daily as $r) {
        $byDay[$r['d']] = $r;
    }
    $chart = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime('-' . $i . ' days'));
        $chart[$d] = (int)($byDay[$d]['v'] ?? 0);
    }
    $peak = max(1, max($chart));
    $eventLabels = ['view' => 'Viewed', 'add_to_cart' => 'Added to cart', 'checkout' => 'Started checkout'];
?>
  <div class="stats">
    <div><strong><?= $live ?></strong><span>on the site now</span></div>
    <div><strong><?= number_format($visitors) ?></strong><span>visitors</span></div>
    <div><strong><?= number_format($views) ?></strong><span>page views</span></div>
    <div><strong><?= (int)$orders['n'] ?></strong><span>orders, <?= money((int)$orders['total']) ?></span></div>
  </div>

  <h2>Shopper funnel</h2>
  <div class="table-wrap">
    <table class="report">
      <tbody>
        <tr><th>Visited</th><td><?= number_format($visitors) ?></td><td></td></tr>
        <tr><th>Viewed a product</th><td><?= number_format($sawProd) ?></td><td><?= $pct($sawProd, $visitors) ?></td></tr>
        <tr><th>Added to cart</th><td><?= number_format($added) ?></td><td><?= $pct($added, $visitors) ?></td></tr>
        <tr><th>Started checkout</th><td><?= number_format($checkouts) ?></td><td><?= $pct($checkouts, $visitors) ?></td></tr>
        <tr><th>Ordered</th><td><?= (int)$orders['n'] ?></td><td><?= $pct((int)$orders['n'], $visitors) ?></td></tr>
      </tbody>
    </table>
  </div>

  <?php if ($days > 1): ?>
    <h2>Visitors per day</h2>
    <div class="bars" role="img" aria-label="Visitors per day">
      <?php foreach ($chart as $d => $v): ?>
        <div class="bar" title="<?= e(date('D M j', strtotime($d)) . ': ' . $v . ' visitors') ?>">
          <span style="height: <?= round(100 * $v / $peak) ?>%"></span>
          <?php if ($days <= 30): ?><small><?= e(date($days <= 7 ? 'D' : 'j', strtotime($d))) ?></small><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="muted-note">Busiest day: <?= $peak > 1 || max($chart) ? max($chart) . ' visitors' : 'none yet' ?>.</p>
  <?php endif; ?>

  <div class="order-cols">
    <section>
      <h2>Products</h2>
      <?php if (!$products): ?><p class="empty">No product views yet.</p><?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Product</th><th>Views</th><th>Added to cart</th></tr></thead>
        <tbody>
        <?php foreach ($products as $r): ?>
          <tr><td><a href="/product.php?slug=<?= e(rawurlencode($r['product'])) ?>" target="_blank" rel="noopener"><?= e($pname($r['product'])) ?></a></td><td><?= (int)$r['views'] ?></td><td><?= (int)$r['adds'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>

      <h2>Pages</h2>
      <?php if (!$pages): ?><p class="empty">No page views yet.</p><?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Page</th><th>Views</th><th>Visitors</th></tr></thead>
        <tbody>
        <?php foreach ($pages as $r): ?>
          <tr><td><?= e($r['path']) ?></td><td><?= (int)$r['n'] ?></td><td><?= (int)$r['v'] ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
    <section>
      <h2>Where visitors came from</h2>
      <div class="table-wrap"><table>
        <tbody>
        <?php foreach ($sources as $r): ?>
          <tr><td><?= e($r['src'] !== '' ? $r['src'] : 'Direct or unknown') ?></td><td><?= (int)$r['v'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$sources): ?><tr><td class="empty">No visits yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>

      <?php if ($campaigns): ?>
        <h2>Campaigns (UTM)</h2>
        <div class="table-wrap"><table>
          <tbody>
          <?php foreach ($campaigns as $r): ?>
            <tr><td><?= e(implode(' / ', array_filter([$r['utm_source'], $r['utm_medium'], $r['utm_campaign']]))) ?></td><td><?= (int)$r['v'] ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>

      <h2>Devices</h2>
      <div class="table-wrap"><table>
        <tbody>
        <?php foreach ($devices as $r): ?>
          <tr><td><?= e(ucfirst($r['device'])) ?></td><td><?= (int)$r['v'] ?></td><td><?= $pct((int)$r['v'], $visitors) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$devices): ?><tr><td class="empty">No visits yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </section>
  </div>

  <h2>Recent activity</h2>
  <?php if (!$recent): ?><p class="empty">Nothing yet. Activity appears here as people browse the store.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Time</th><th>Visitor</th><th>Activity</th><th>From</th><th>Device</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr>
        <td class="nowrap"><?= e(date(date('Y-m-d', strtotime($r['created_at'])) === date('Y-m-d') ? 'g:ia' : 'M j, g:ia', strtotime($r['created_at']))) ?></td>
        <td><code title="Anonymous, changes daily"><?= e(substr($r['visitor'], 0, 6)) ?></code></td>
        <td><?= e($eventLabels[$r['event']] ?? $r['event']) ?>
          <?= $r['product'] ? e($pname($r['product'])) : e($r['path']) ?>
          <?= $r['value_cents'] !== null ? '(' . money((int)$r['value_cents']) . ')' : '' ?></td>
        <td><?= e($r['referrer_host'] ?? '') ?></td>
        <td><?= e($r['device']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <p class="muted-note">Counts people who browse with JavaScript on and Do Not Track off. Bots and your own admin sessions are left out. No IP addresses are stored; a visitor ID changes every day, so "visitors" means people per day. Data older than <?= ACTIVITY_KEEP_DAYS ?> days is removed by the daily cron.</p>
<?php endif; ?>
<?php admin_footer();
