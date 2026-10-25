<?php
// Step 2 of connecting Shopify: Shopify sends the browser back here after
// the app is approved. Checks state and hmac, trades the code for an
// offline Admin token, saves it outside public_html and locks reconnects.
// Registered redirect URL: https://discount-yarn.com/shopify/callback.php

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/shopify_connect.php';

shopify_require_unlock(false);

$fail = function (string $message, int $status = 400): void {
    shopify_connect_page('Shopify connection', '<p class="error">' . e($message) . '</p><p><a href="/shopify/install.php">Start again</a></p>', $status);
};

// The state is single use.
$pending = $_SESSION['shopify_oauth'] ?? null;
unset($_SESSION['shopify_oauth']);
$state = (string)($_GET['state'] ?? '');
if (!is_array($pending) || $state === '' || !hash_equals((string)$pending['state'], $state)) {
    $fail('This approval link did not come from this browser session (state mismatch).');
}
if (time() - (int)$pending['at'] > 900) {
    $fail('The approval took too long and expired.');
}

$shop = strtolower((string)($_GET['shop'] ?? ''));
if (!preg_match(SHOPIFY_SHOP_RE, $shop) || $shop !== (string)$pending['shop']) {
    $fail('Shopify sent back an unexpected store address.');
}

if (!shopify_oauth_hmac_valid((string)($_SERVER['QUERY_STRING'] ?? ''), (string)config('shopify.client_secret'))) {
    $fail('The request signature (hmac) did not check out. Make sure shopify.client_secret matches the app in the Dev Dashboard.');
}
$timestamp = (int)($_GET['timestamp'] ?? 0);
if ($timestamp > 0 && abs(time() - $timestamp) > 3600) {
    $fail('The approval is too old.');
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    $fail('Shopify did not send an authorization code.');
}

try {
    $token = shopify_exchange_code($shop, $code);
    file_put_contents(shopify_connect_lock_path(), 'Connected to ' . $shop . ' on ' . date('c') . "\n");
    @chmod(shopify_connect_lock_path(), 0600);
} catch (ShopifyError $ex) {
    error_log('Shopify connect failed: ' . $ex->getMessage());
    $fail($ex->getMessage(), 502);
}
unset($_SESSION['shopify_reconnect_until']);

$scopes = trim((string)$token['scope']);
shopify_connect_page('Connected', '<p class="notice">Connected to ' . e($shop) . '.</p>'
    . '<p>Scopes granted: ' . e($scopes !== '' ? $scopes : 'none reported') . '</p>'
    . '<p><a href="/admin/shopify.php">Open the Shopify test page</a></p>');
