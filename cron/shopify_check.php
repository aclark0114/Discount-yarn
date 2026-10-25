<?php
// Prints the Shopify connection status (shop name, product count, scopes).
// Run from cPanel Terminal:
//   php -q /home/xwy78w88pv1w/public_html/discount-yarn.com/cron/shopify_check.php

if (PHP_SAPI !== 'cli' || isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/shopify.php';

$failed = false;
foreach (shopify_connection_report() as [$label, $ok, $detail]) {
    printf("%-22s %-8s %s\n", $label, $ok ? 'OK' : 'PROBLEM', $detail);
    $failed = $failed || !$ok;
}
exit($failed ? 1 : 0);
