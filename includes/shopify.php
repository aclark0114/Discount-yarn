<?php
// Shopify API access for the Stars Hollow store.
// - Admin API: an offline token from the OAuth authorization code grant
//   (/shopify/install.php then /shopify/callback.php), saved as
//   shopify_token.json next to the config file, outside public_html.
// - Storefront API: the Headless channel token in shopify.storefront_token.
// Tokens are never logged or printed.

declare(strict_types=1);

class ShopifyError extends RuntimeException
{
}

const SHOPIFY_SHOP_RE = '/^[a-z0-9-]+\.myshopify\.com$/';
// Refresh an expiring token this long before it runs out.
const SHOPIFY_TOKEN_MARGIN = 300;

function shopify_domain(): string
{
    $domain = strtolower(trim((string)config('shopify.shop_domain')));
    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = rtrim((string)$domain, '/');
    if (!preg_match(SHOPIFY_SHOP_RE, $domain)) {
        throw new ShopifyError('shopify.shop_domain must be the store\'s *.myshopify.com address (find it in Shopify admin > Settings > Domains), not starshollowyarns.com.');
    }
    return $domain;
}

function shopify_base(): string
{
    // Local tests point this at a mock server. Never set on the live site.
    $test = getenv('SHOPIFY_TEST_BASE');
    return $test ? rtrim($test, '/') : 'https://' . shopify_domain();
}

function shopify_api_version(): string
{
    return (string)config('shopify.api_version', '2026-10');
}

function shopify_cache_dir(): string
{
    $dir = (string)config('cache_dir', '');
    if ($dir === '') {
        throw new ShopifyError('Set cache_dir in the config to a writable folder outside public_html.');
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        throw new ShopifyError('cache_dir does not exist and could not be created: ' . $dir);
    }
    if (!is_writable($dir)) {
        throw new ShopifyError('cache_dir is not writable: ' . $dir);
    }
    return rtrim($dir, '/');
}

function shopify_cache_read(string $name): ?array
{
    $path = shopify_cache_dir() . '/' . $name;
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function shopify_cache_write(string $name, array $data): void
{
    $path = shopify_cache_dir() . '/' . $name;
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, json_encode($data));
    @chmod($tmp, 0600);
    rename($tmp, $path);
}

function shopify_cache_delete(string $name): void
{
    @unlink(shopify_cache_dir() . '/' . $name);
}

// Runs $fn while holding an exclusive lock, so only one request refreshes a token.
function shopify_with_lock(string $name, callable $fn)
{
    $fh = fopen(shopify_cache_dir() . '/' . $name . '.lock', 'c');
    if (!$fh) {
        return $fn();
    }
    try {
        flock($fh, LOCK_EX);
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

// Returns [status, decoded body or null, raw body].
function shopify_http(string $url, array $headers, string $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new ShopifyError('Could not reach Shopify: ' . $error);
    }
    $decoded = json_decode((string)$raw, true);
    return [$status, is_array($decoded) ? $decoded : null, (string)$raw];
}

// The folder holding the config file. The OAuth token is saved here, so it
// must be outside the web root.
function shopify_config_dir(): string
{
    $dir = dirname(CONFIG_FILE);
    $docroot = realpath(__DIR__ . '/..') ?: '';
    $real = realpath($dir) ?: $dir;
    if ($docroot !== '' && str_starts_with(rtrim($real, '/') . '/', rtrim($docroot, '/') . '/')) {
        throw new ShopifyError('The config file is inside public_html, so the Shopify token has nowhere safe to go. Move it to /home/<cpanel user>/discount-yarn-config.php first.');
    }
    return $real;
}

function shopify_token_path(): string
{
    return shopify_config_dir() . '/shopify_token.json';
}

function shopify_connect_lock_path(): string
{
    return shopify_config_dir() . '/shopify_connected.lock';
}

function shopify_connect_locked(): bool
{
    try {
        return is_file(shopify_connect_lock_path());
    } catch (ShopifyError $ex) {
        return false;
    }
}

// The saved OAuth token record, or null when the store hasn't been connected.
function shopify_token_read(): ?array
{
    $path = shopify_token_path();
    if (!is_file($path)) {
        return null;
    }
    $t = json_decode((string)file_get_contents($path), true);
    return is_array($t) && !empty($t['access_token']) ? $t : null;
}

function shopify_token_save(array $token): void
{
    $path = shopify_token_path();
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($token)) === false) {
        throw new ShopifyError('Could not write the Shopify token next to the config file. Check that folder is writable.');
    }
    chmod($tmp, 0600);
    rename($tmp, $path);
    @chmod($path, 0600);
}

function shopify_has_admin_token(): bool
{
    try {
        return shopify_token_read() !== null;
    } catch (ShopifyError $ex) {
        return false;
    }
}

function shopify_token_scopes(): array
{
    try {
        $t = shopify_token_read();
    } catch (ShopifyError $ex) {
        return [];
    }
    return $t ? array_values(array_filter(array_map('trim', explode(',', (string)($t['scope'] ?? ''))))) : [];
}

// Without a saved Admin token the catalog and live checks fall back to the
// Storefront API alone.
function shopify_storefront_only(): bool
{
    return !shopify_has_admin_token();
}

// Lowering Shopify stock after a sale needs the Admin token with write_inventory.
function shopify_can_push_stock(): bool
{
    return in_array('write_inventory', shopify_token_scopes(), true);
}

const SHOPIFY_NOT_CONNECTED = 'The store is not connected to Shopify\'s Admin API yet. An admin needs to open /shopify/install.php and approve the app.';

function shopify_redirect_uri(): string
{
    return (string)config('shopify.redirect_uri', 'https://discount-yarn.com/shopify/callback.php');
}

function shopify_oauth_scopes(): string
{
    return (string)config('shopify.scopes', 'read_products,read_inventory');
}

// Checks the hmac Shopify adds to the OAuth callback: drop hmac (and the
// legacy signature), sort the rest by key, join as key=value with &, and
// HMAC-SHA256 with the client secret. Both the raw and the decoded form of
// the values are tried, since either is a correct reading of the docs.
function shopify_oauth_hmac_valid(string $queryString, string $secret): bool
{
    $pairs = [];
    $given = '';
    foreach (explode('&', $queryString) as $part) {
        if ($part === '') {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $key = urldecode($k);
        if ($key === 'hmac') {
            $given = strtolower(urldecode($v));
            continue;
        }
        if ($key === 'signature') {
            continue;
        }
        $pairs[] = [$key, $k, $v];
    }
    if ($given === '' || $secret === '') {
        return false;
    }
    usort($pairs, fn($a, $b) => strcmp($a[0], $b[0]));
    $raw = implode('&', array_map(fn($p) => $p[1] . '=' . $p[2], $pairs));
    $decoded = implode('&', array_map(fn($p) => $p[0] . '=' . urldecode($p[2]), $pairs));
    foreach ([$raw, $decoded] as $message) {
        if (hash_equals(hash_hmac('sha256', $message, $secret), $given)) {
            return true;
        }
    }
    return false;
}

// Trades the callback's code for an offline token and saves it.
function shopify_exchange_code(string $shop, string $code): array
{
    [$status, $json] = shopify_http(
        (getenv('SHOPIFY_TEST_BASE') ?: 'https://' . $shop) . '/admin/oauth/access_token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id'     => (string)config('shopify.client_id'),
            'client_secret' => (string)config('shopify.client_secret'),
            'code'          => $code,
        ])
    );
    if ($status !== 200 || empty($json['access_token'])) {
        $detail = trim((string)($json['error'] ?? '') . ' ' . (string)($json['error_description'] ?? ''));
        throw new ShopifyError('Shopify did not return a token (HTTP ' . $status . ($detail !== '' ? ', ' . $detail : '') . '). Start again from /shopify/install.php.');
    }
    $token = [
        'shop'         => $shop,
        'access_token' => (string)$json['access_token'],
        'scope'        => (string)($json['scope'] ?? ''),
        'connected_at' => time(),
    ];
    if (!empty($json['refresh_token'])) {
        $token['refresh_token'] = (string)$json['refresh_token'];
    }
    if (!empty($json['expires_in'])) {
        $token['expires_at'] = time() + (int)$json['expires_in'];
    }
    if (!empty($json['refresh_token_expires_in'])) {
        $token['refresh_expires_at'] = time() + (int)$json['refresh_token_expires_in'];
    }
    shopify_token_save($token);
    return $token;
}

// Returns the saved Admin token, refreshing it first if it expires soon and
// Shopify gave us a refresh token. Non-expiring offline tokens never refresh.
function shopify_admin_token(): string
{
    $t = shopify_token_read();
    if (!$t) {
        throw new ShopifyError(SHOPIFY_NOT_CONNECTED);
    }
    if (empty($t['expires_at']) || (int)$t['expires_at'] - SHOPIFY_TOKEN_MARGIN > time()) {
        return $t['access_token'];
    }
    if (empty($t['refresh_token'])) {
        throw new ShopifyError('The Shopify Admin token has expired. Reconnect at /shopify/install.php.');
    }
    return shopify_with_lock('admin_token_refresh', function () {
        $t = shopify_token_read();
        if (empty($t['expires_at']) || (int)$t['expires_at'] - SHOPIFY_TOKEN_MARGIN > time()) {
            return $t['access_token'];
        }
        [$status, $json] = shopify_http(
            shopify_base() . '/admin/oauth/access_token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'client_id'     => (string)config('shopify.client_id'),
                'client_secret' => (string)config('shopify.client_secret'),
                'grant_type'    => 'refresh_token',
                'refresh_token' => (string)$t['refresh_token'],
            ])
        );
        if ($status !== 200 || empty($json['access_token'])) {
            error_log('Shopify token refresh failed (HTTP ' . $status . ')');
            throw new ShopifyError('Could not refresh the Shopify Admin token (HTTP ' . $status . '). Reconnect at /shopify/install.php.');
        }
        $t['access_token'] = (string)$json['access_token'];
        $t['scope'] = (string)($json['scope'] ?? $t['scope']);
        $t['refresh_token'] = (string)($json['refresh_token'] ?? $t['refresh_token']);
        $t['expires_at'] = time() + (int)($json['expires_in'] ?? 3600);
        shopify_token_save($t);
        return $t['access_token'];
    });
}

function shopify_graphql_result(int $status, ?array $json, string $raw, string $api): array
{
    if ($status === 401 || $status === 403) {
        throw new ShopifyError($api . ' API refused the token (HTTP ' . $status . '). Check the app is installed and has the needed scopes.');
    }
    if ($status !== 200 || $json === null) {
        throw new ShopifyError($api . ' API request failed (HTTP ' . $status . ').');
    }
    if (!empty($json['errors'])) {
        $msgs = array_map(fn($e) => is_array($e) ? (string)($e['message'] ?? '') : (string)$e, (array)$json['errors']);
        throw new ShopifyError($api . ' API error: ' . implode('; ', array_filter($msgs)));
    }
    return (array)($json['data'] ?? []);
}

function shopify_admin_graphql(string $query, array $variables = []): array
{
    $token = shopify_admin_token();
    [$status, $json, $raw] = shopify_http(
        shopify_base() . '/admin/api/' . shopify_api_version() . '/graphql.json',
        ['Content-Type: application/json', 'X-Shopify-Access-Token: ' . $token],
        json_encode(['query' => $query, 'variables' => (object)$variables])
    );
    if ($status === 401) {
        throw new ShopifyError('Shopify rejected the saved Admin token (the app may have been uninstalled). Reconnect at /shopify/install.php.');
    }
    return shopify_graphql_result($status, $json, $raw, 'Admin');
}

// Headless channel tokens come as a public token or a private one (shpss_...).
// They are sent in different headers; storefront_token_type overrides the guess.
function shopify_storefront_header(string $token): string
{
    $type = strtolower((string)config('shopify.storefront_token_type', 'auto'));
    if ($type === 'auto') {
        $type = str_starts_with($token, 'shpss_') ? 'private' : 'public';
    }
    return ($type === 'private' ? 'Shopify-Storefront-Private-Token: ' : 'X-Shopify-Storefront-Access-Token: ') . $token;
}

// The Headless channel token from config, used for every Storefront API call.
function shopify_storefront_token(): string
{
    $token = trim((string)config('shopify.storefront_token', ''));
    if ($token === '') {
        throw new ShopifyError('Set shopify.storefront_token to the Headless channel token (Shopify admin > Sales channels > Headless > your storefront).');
    }
    return $token;
}

function shopify_storefront_graphql(string $query, array $variables = []): array
{
    [$status, $json, $raw] = shopify_http(
        shopify_base() . '/api/' . shopify_api_version() . '/graphql.json',
        ['Content-Type: application/json', shopify_storefront_header(shopify_storefront_token())],
        json_encode(['query' => $query, 'variables' => (object)$variables])
    );
    if ($status === 401 || $status === 403) {
        throw new ShopifyError('Shopify refused the Storefront token (HTTP ' . $status . '). Copy it again from Shopify admin > Sales channels > Headless > your storefront. '
            . 'If it is the private token, set shopify.storefront_token_type to private; if public, set it to public.');
    }
    try {
        return shopify_graphql_result($status, $json, $raw, 'Storefront');
    } catch (ShopifyError $ex) {
        if (stripos($ex->getMessage(), 'quantityAvailable') !== false || stripos($ex->getMessage(), 'unauthenticated_read_product_inventory') !== false) {
            throw new ShopifyError('The Storefront token can\'t read stock counts. In Shopify admin > Sales channels > Headless > your storefront > Storefront API permissions, turn on "Read product inventory" (unauthenticated_read_product_inventory) and save.');
        }
        throw $ex;
    }
}

// Runs the connection checks used by the admin page and the CLI script:
// shop name, product count (Storefront API) and stock for three products
// (Admin API). Returns [label, ok, detail] rows. Never includes token values.
function shopify_connection_report(): array
{
    $rows = [];
    try {
        $rows[] = ['Shop domain', true, shopify_domain()];
        $rows[] = ['Config file', true, CONFIG_FILE];
        $dir = realpath(shopify_cache_dir()) ?: shopify_cache_dir();
        $docroot = realpath(__DIR__ . '/..') ?: '';
        $public = $docroot !== '' && str_starts_with($dir . '/', rtrim($docroot, '/') . '/');
        $rows[] = ['Cache folder', !$public, $dir . ($public ? ' (inside the web root: move it outside public_html)' : '')];
    } catch (ShopifyError $ex) {
        $rows[] = ['Setup', false, $ex->getMessage()];
        return $rows;
    }

    // Storefront API: shop name and a full product count.
    try {
        $count = 0;
        $cursor = null;
        $name = null;
        for ($page = 0; $page < 100; $page++) {
            $d = shopify_storefront_graphql(
                'query Count($cursor: String) { shop { name } products(first: 250, after: $cursor) { pageInfo { hasNextPage endCursor } nodes { id } } }',
                ['cursor' => $cursor]
            );
            $name = $name ?? ($d['shop']['name'] ?? '?');
            $count += count((array)($d['products']['nodes'] ?? []));
            if (empty($d['products']['pageInfo']['hasNextPage'])) {
                break;
            }
            $cursor = $d['products']['pageInfo']['endCursor'];
        }
        $rows[] = ['Shop name', true, (string)$name];
        $rows[] = ['Products (Storefront)', $count > 0, $count > 0 ? $count . ' available to the Headless channel'
            : 'None. In Shopify, make the products available to the Headless sales channel.'];
    } catch (ShopifyError $ex) {
        $rows[] = ['Storefront API', false, $ex->getMessage()];
    }

    // Admin API: the saved OAuth token, then stock for three products.
    try {
        $t = shopify_token_read();
    } catch (ShopifyError $ex) {
        $rows[] = ['Admin connection', false, $ex->getMessage()];
        return $rows;
    }
    if (!$t) {
        $rows[] = ['Admin connection', false, SHOPIFY_NOT_CONNECTED];
        return $rows;
    }
    $rows[] = ['Admin connection', true, 'Connected to ' . ($t['shop'] ?? '?') . ' on ' . date('M j, Y', (int)($t['connected_at'] ?? time()))
        . '. Scopes: ' . (($t['scope'] ?? '') ?: 'none reported')
        . (!empty($t['expires_at']) ? '. Token renews automatically (expires ' . date('M j, g:ia', (int)$t['expires_at']) . ')' : '')];
    try {
        $d = shopify_admin_graphql('{ products(first: 3, query: "status:active") { nodes {
            title totalInventory variants(first: 5) { nodes { title inventoryQuantity } } } } }');
        $products = (array)($d['products']['nodes'] ?? []);
        if (!$products) {
            $rows[] = ['Inventory (Admin)', false, 'No active products found.'];
        }
        foreach ($products as $p) {
            $parts = [];
            foreach ((array)($p['variants']['nodes'] ?? []) as $v) {
                $parts[] = ($v['title'] === 'Default Title' ? 'Stock' : $v['title']) . ': ' . ($v['inventoryQuantity'] ?? 'not tracked');
            }
            $rows[] = ['Inventory (Admin)', true, $p['title'] . ', ' . (int)($p['totalInventory'] ?? 0) . ' total (' . implode(', ', $parts) . ')'];
        }
    } catch (ShopifyError $ex) {
        $rows[] = ['Inventory (Admin)', false, $ex->getMessage()];
    }
    return $rows;
}
