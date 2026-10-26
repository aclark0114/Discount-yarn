<?php
// Included at the top of every admin page except login.php.

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/shopify.php';
require_once __DIR__ . '/../includes/admin_password.php';

header('X-Robots-Tag: noindex, nofollow');

const ADMIN_IDLE_SECONDS = 4 * 3600;

function admin_logged_in(): bool
{
    if (empty($_SESSION['admin']) || (time() - (int)($_SESSION['admin_seen'] ?? 0)) > ADMIN_IDLE_SECONDS
        || ($_SESSION['admin_pw'] ?? '') !== admin_password_version()) {
        unset($_SESSION['admin'], $_SESSION['admin_seen'], $_SESSION['admin_pw']);
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
<link rel="stylesheet" href="/assets/admin.css?v=9">
</head>
<body>
<header class="admin-bar">
  <a class="admin-brand" href="/admin/"><?= $store ?> admin</a>
  <nav>
    <a href="/admin/">Products</a>
    <a href="/admin/product_edit.php">Add product</a>
    <a href="/admin/import.php">CSV import</a>
    <a href="/admin/hide.php">Hide products</a>
    <a href="/admin/orders.php">Orders</a>
    <a href="/admin/visitors.php">Visitors</a>
    <a href="/admin/email.php">Email</a>
    <a href="/admin/pricing.php">Pricing</a>
    <a href="/admin/shopify.php">Shopify</a>
    <a href="/admin/stripe.php">Stripe</a>
    <a href="/admin/site.php">Store status</a>
    <a href="/" target="_blank" rel="noopener">View store</a>
    <a href="/admin/password.php">Change password</a>
    <a href="/admin/logout.php">Log out</a>
  </nav>
</header>
<main class="admin-main">
<?php
    if (!shopify_has_admin_token() && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'shopify.php') {
        echo '<p class="error">Shopify Admin API not connected. <a href="/shopify/install.php">Connect to Shopify</a></p>';
    }
    if (maintenance_state() !== null && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'site.php') {
        echo '<p class="error">The store is under construction: shoppers see the back-soon page. <a href="/admin/site.php">Store status</a></p>';
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
