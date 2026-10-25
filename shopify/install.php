<?php
// Step 1 of connecting the Stars Hollow Shopify store (OAuth authorization
// code grant). Sends the browser to Shopify to approve the app, asking for
// an offline token.
//   https://discount-yarn.com/shopify/install.php?shop=xxxx.myshopify.com

require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/shopify_connect.php';

shopify_require_unlock(true);

$shop = strtolower(trim((string)($_GET['shop'] ?? '')));
if ($shop === '') {
    $shop = strtolower(trim((string)config('shopify.shop_domain')));
}
if (!preg_match(SHOPIFY_SHOP_RE, $shop)) {
    shopify_connect_page('Shopify connection', '<p class="error">That is not a valid store address. Use the store\'s xxxx.myshopify.com address.</p>', 400);
}
// Only the store in the config can be connected, so nobody can point this
// site at a different shop.
try {
    if ($shop !== shopify_domain()) {
        shopify_connect_page('Shopify connection', '<p class="error">This site is set up for ' . e(shopify_domain()) . ' only.</p>', 400);
    }
    shopify_config_dir();  // fails early if the token would land inside public_html
} catch (ShopifyError $ex) {
    shopify_connect_page('Shopify connection', '<p class="error">' . e($ex->getMessage()) . '</p>', 500);
}
$clientId = (string)config('shopify.client_id');
if ($clientId === '' || (string)config('shopify.client_secret') === '') {
    shopify_connect_page('Shopify connection', '<p class="error">Set shopify.client_id and shopify.client_secret in the config first (Dev Dashboard > app > Settings).</p>', 500);
}

$state = bin2hex(random_bytes(16));
$_SESSION['shopify_oauth'] = ['state' => $state, 'shop' => $shop, 'at' => time()];

// No grant_options[]=per-user, so Shopify issues an offline token.
$authorize = 'https://' . $shop . '/admin/oauth/authorize?' . http_build_query([
    'client_id'    => $clientId,
    'scope'        => shopify_oauth_scopes(),
    'redirect_uri' => shopify_redirect_uri(),
    'state'        => $state,
]);
header('Cache-Control: no-store');
redirect($authorize);
