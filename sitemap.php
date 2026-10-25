<?php
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');
$urls = ['', 'shop.php', 'shop.php?sale=1', 'about.php', 'shipping.php'];
foreach (YARN_WEIGHTS as $w) {
    $urls[] = 'shop.php?weight=' . rawurlencode($w);
}
$products = db()->query('SELECT slug, updated_at FROM products WHERE is_active = 1 ORDER BY id')->fetchAll();

echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($urls as $u) {
    echo '  <url><loc>', e(url($u)), "</loc></url>\n";
}
foreach ($products as $p) {
    echo '  <url><loc>', e(url('product.php?slug=' . rawurlencode($p['slug']))), '</loc><lastmod>',
        e(date('Y-m-d', strtotime($p['updated_at']))), "</lastmod></url>\n";
}
echo "</urlset>\n";
