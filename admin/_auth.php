<?php
// Included at the top of every admin page except login.php.

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/shopify.php';

header('X-Robots-Tag: noindex, nofollow');

const ADMIN_IDLE_SECONDS = 4 * 3600;

function admin_logged_in(): bool
{
    if (empty($_SESSION['admin']) || (time() - (int)($_SESSION['admin_seen'] ?? 0)) > ADMIN_IDLE_SECONDS) {
        unset($_SESSION['admin'], $_SESSION['admin_seen']);
        return false;
    }
    $_SESSION['admin_seen'] = time();
    return true;
}

function admin_header(string $title): void
{
    $store = e(config('store_name'));
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> | <?= $store ?> admin</title>
<link rel="stylesheet" href="/assets/admin.css?v=3">
</head>
<body>
<header class="admin-bar">
  <a class="admin-brand" href="/admin/"><?= $store ?> admin</a>
  <nav>
    <a href="/admin/">Products</a>
    <a href="/admin/product_edit.php">Add product</a>
    <a href="/admin/import.php">CSV import</a>
    <a href="/admin/orders.php">Orders</a>
    <a href="/admin/visitors.php">Visitors</a>
    <a href="/admin/pricing.php">Pricing</a>
    <a href="/admin/shopify.php">Shopify</a>
    <a href="/admin/stripe.php">Stripe</a>
    <a href="/" target="_blank" rel="noopener">View store</a>
    <a href="/admin/logout.php">Log out</a>
  </nav>
</header>
<main class="admin-main">
<?php
    if (!shopify_has_admin_token() && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'shopify.php') {
        echo '<p class="error">Shopify Admin API not connected. <a href="/shopify/install.php">Connect to Shopify</a></p>';
    }
    $msg = flash();
    if ($msg) {
        echo '<p class="notice">' . e($msg) . '</p>';
    }
}

function admin_footer(): void
{
    echo "</main>\n</body>\n</html>\n";
}

if (!admin_logged_in()) {
    redirect('/admin/login.php');
}
