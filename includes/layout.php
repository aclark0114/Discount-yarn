<?php
// Shared storefront header and footer.

declare(strict_types=1);

require_once __DIR__ . '/cart.php';

const ASSET_VERSION = '6';

function page_header(string $title, string $description = '', string $active = '', string $canonical = ''): void
{
    $store = (string)config('store_name');
    $fullTitle = $title === '' ? $store . ' | ' . config('tagline') : $title . ' | ' . $store;
    $desc = $description ?: 'Name-brand yarn below its usual price, from brands knitters already use.';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $canonical = $canonical !== '' ? $canonical : url($path);
    $count = cart_count();
    $nav = [
        '/shop.php'          => 'Shop all',
        '/shop.php?sale=1'   => 'On sale',
        '/shop.php?weight=Worsted' => 'Worsted',
        '/shop.php?weight=Fingering' => 'Fingering',
        '/about.php'         => 'About',
    ];
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($fullTitle) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<link rel="canonical" href="<?= e($canonical) ?>">
<meta property="og:title" content="<?= e($fullTitle) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= e($canonical) ?>">
<?php if (is_file(__DIR__ . '/../assets/favicon-32.png')): ?>
<link rel="icon" href="/assets/favicon-32.png?v=<?= ASSET_VERSION ?>" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png?v=<?= ASSET_VERSION ?>">
<?php else: ?>
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,600;1,400&family=Nunito+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/styles.css?v=<?= ASSET_VERSION ?>">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<div class="announce">Free shipping on orders over <?= money((int)config('shipping.free_over_cents')) ?></div>
<header class="site-header">
  <div class="wrap header-row">
    <button class="nav-toggle" aria-expanded="false" aria-controls="site-nav" aria-label="Menu">
      <span></span><span></span><span></span>
    </button>
    <a class="logo" href="/"><?php if (is_file(__DIR__ . '/../assets/logo.png')): ?><img src="/assets/logo.png?v=<?= ASSET_VERSION ?>" alt="<?= e($store) ?>" height="44"><?php else: ?><?= e($store) ?><?php endif; ?></a>
    <nav id="site-nav" class="site-nav" aria-label="Main">
      <?php foreach ($nav as $href => $label): ?>
        <a href="<?= e($href) ?>"<?= $active === $href ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <a class="cart-link" href="/cart.php" aria-label="Cart, <?= $count ?> items">
      <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.6" d="M5 8h14l-1.2 11.2a1 1 0 0 1-1 .8H7.2a1 1 0 0 1-1-.8L5 8Zm4 0V6a3 3 0 0 1 6 0v2"/></svg>
      <span class="cart-count"><?= $count ?></span>
    </a>
  </div>
</header>
<main id="main">
<?php
    $msg = flash();
    if ($msg) {
        echo '<div class="wrap"><p class="notice">' . e($msg) . '</p></div>';
    }
}

function page_footer(): void
{
    $store = (string)config('store_name');
    ?>
</main>
<footer class="site-footer">
  <div class="wrap footer-grid">
    <div>
      <p class="logo logo-sm"><?= e($store) ?></p>
      <p><?= e(config('tagline')) ?></p>
    </div>
    <div>
      <h2>Shop</h2>
      <a href="/shop.php">All yarn</a>
      <a href="/shop.php?sale=1">On sale</a>
      <a href="/shop.php?sort=new">New arrivals</a>
    </div>
    <div>
      <h2>Help</h2>
      <a href="/shipping.php">Shipping and returns</a>
      <a href="mailto:<?= e(config('support_email')) ?>"><?= e(config('support_email')) ?></a>
    </div>
  </div>
  <div class="wrap footer-base">&copy; <?= date('Y') ?> <?= e($store) ?>. Payments processed securely by Stripe.</div>
</footer>
<script src="/assets/script.js?v=<?= ASSET_VERSION ?>" defer></script>
</body>
</html>
<?php
}

function product_card(array $p): void
{
    $off = percent_off($p);
    ?>
  <article class="card">
    <a class="card-img" href="/product.php?slug=<?= e(rawurlencode($p['slug'])) ?>">
      <img src="<?= e(product_image($p, 600)) ?>" alt="<?= e($p['name'] . ($p['colorway'] ? ', ' . $p['colorway'] : '')) ?>" loading="lazy" width="600" height="600">
      <?php if ($off > 0): ?><span class="badge"><?= $off ?>% off</span><?php endif; ?>
      <?php if ((int)$p['stock'] <= 0): ?><span class="badge badge-out">Sold out</span><?php endif; ?>
    </a>
    <div class="card-body">
      <?php if ($p['brand'] !== ''): ?><p class="card-brand"><?= e($p['brand']) ?></p><?php endif; ?>
      <h3><a href="/product.php?slug=<?= e(rawurlencode($p['slug'])) ?>"><?= e($p['name']) ?></a></h3>
      <p class="card-meta"><?= e(implode(' · ', array_filter([$p['weight'], $p['colorway']]))) ?></p>
      <p class="price">
        <?php if ($off > 0): ?><s><?= money((int)$p['price_cents']) ?></s> <?php endif; ?>
        <span><?= money(effective_price($p)) ?></span>
      </p>
    </div>
  </article>
<?php
}
