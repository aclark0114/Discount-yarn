<?php
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';

$featured = db()->query(
    'SELECT * FROM products WHERE is_active = 1 AND stock > 0
     ORDER BY is_featured DESC, (sale_price_cents IS NOT NULL AND sale_price_cents < price_cents) DESC, created_at DESC
     LIMIT 8'
)->fetchAll();

$weights = db()->query(
    "SELECT weight, COUNT(*) AS n FROM products WHERE is_active = 1 AND stock > 0 AND weight <> '' GROUP BY weight"
)->fetchAll(PDO::FETCH_KEY_PAIR);

page_header('');
?>
<section class="hero">
  <div class="wrap hero-inner">
    <div class="hero-copy reveal">
      <p class="eyebrow">Closeouts, overstock and sale skeins</p>
      <h1>Good yarn, gently priced.</h1>
      <p class="lede">We buy discontinued colorways and extra stock from mills and shops, then pass the savings to you. When a lot is gone, it's gone.</p>
      <div class="hero-actions">
        <a class="btn" href="/shop.php">Shop all yarn</a>
        <a class="btn btn-ghost" href="/shop.php?sale=1">See what's on sale</a>
      </div>
    </div>
    <div class="hero-art reveal" aria-hidden="true">
      <img src="/assets/hero-skeins.svg" alt="" width="520" height="420">
    </div>
  </div>
</section>

<section class="wrap section">
  <div class="section-head">
    <h2>Fresh on the shelf</h2>
    <a href="/shop.php?sort=new">View all</a>
  </div>
  <?php if ($featured): ?>
    <div class="grid">
      <?php foreach ($featured as $p) { product_card($p); } ?>
    </div>
  <?php else: ?>
    <p class="empty">New yarn is on the way. Check back soon.</p>
  <?php endif; ?>
</section>

<section class="band">
  <div class="wrap section">
    <h2 class="center">Shop by weight</h2>
    <ul class="weights">
      <?php foreach (YARN_WEIGHTS as $w): ?>
        <li><a href="/shop.php?weight=<?= e(rawurlencode($w)) ?>">
          <span class="weight-name"><?= e($w) ?></span>
          <?php $n = (int)($weights[$w] ?? 0); ?><span class="weight-count"><?= $n ?> <?= $n === 1 ? 'yarn' : 'yarns' ?></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="wrap section promises">
  <div class="reveal">
    <h3>Real brands, lower prices</h3>
    <p>Discontinued shades and extra dye lots from names you already knit with.</p>
  </div>
  <div class="reveal">
    <h3>Dye lots kept together</h3>
    <p>Buy a sweater's worth and every skein comes from the same lot.</p>
  </div>
  <div class="reveal">
    <h3>Ships in 2 business days</h3>
    <p>Free shipping over <?= money((int)config('shipping.free_over_cents')) ?>. Flat <?= money((int)config('shipping.flat_rate_cents')) ?> otherwise.</p>
  </div>
</section>
<?php page_footer();
