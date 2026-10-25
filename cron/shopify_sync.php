<?php
// Cron (every 15 minutes): copies the Stars Hollow Shopify catalog into the
// products table.
//   php -q /home/xwy78w88pv1w/public_html/discount-yarn.com/cron/shopify_sync.php

if (PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/shopify_sync.php';

try {
    $s = shopify_sync_catalog();
} catch (Throwable $ex) {
    fwrite(STDERR, 'Shopify sync failed: ' . $ex->getMessage() . "\n");
    exit(1);
}
printf("%d products, %d variants: %d added, %d updated, %d hidden in %ds\n",
    $s['products'], $s['variants'], $s['added'], $s['updated'], $s['hidden'], $s['seconds']);
