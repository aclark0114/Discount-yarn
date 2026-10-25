<?php
// Shopify API access for the Stars Hollow store. Admin API tokens come from
// the client credentials grant (they last 24 hours) and are cached in
// cache_dir. The Storefront token is read from config or created once with
// the Admin API and cached. Tokens are never logged or printed.

declare(strict_types=1);

class ShopifyError extends RuntimeException
{
}

// Token refresh margin before Shopify's 24 hour expiry.
const SHOPIFY_TOKEN_MARGIN = 300;
// After a failed token request, wait this long before trying again on page loads.
const SHOPIFY_AUTH_BACKOFF = 300;

function shopify_domain(): string
{
    $domain = strtolower(trim((string)config('shopify.shop_domain')));
    $domain = preg_replace('#^https?://#', '', $domain);
    $domain = rtrim((string)$domain, '/');
    if (!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $domain)) {
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

// Turns a failed token response into a message for the admin.
function shopify_auth_error(int $status, ?array $json, string $raw): string
{
    $code = (string)($json['error'] ?? '');
    $desc = (string)($json['error_description'] ?? '');
    if ($code === 'shop_not_permitted' || str_contains($raw, 'shop_not_permitted')) {
        return 'Shopify refused the token request (shop_not_permitted). The app and the store are in different Shopify organizations: '
            . 'the app was probably created under the collaborator\'s organization instead of the store owner\'s. '
            . 'Recreate the app in the Dev Dashboard of the organization that owns the store, install it, and update client_id and client_secret.';
    }
    if ($status === 401 || $code === 'invalid_client') {
        return 'Shopify rejected the client ID or client secret (' . ($code ?: 'HTTP 401') . '). Copy both again from Dev Dashboard > app > Settings.';
    }
    if ($status === 404) {
        return 'No Shopify store answered at ' . shopify_domain() . '. Check shopify.shop_domain.';
    }
    $detail = trim($code . ($desc !== '' ? ': ' . $desc : ''));
    return 'Shopify token request failed (HTTP ' . $status . ($detail !== '' ? ', ' . $detail : '') . ').';
}

// Returns a valid Admin API access token, requesting a new one when the
// cached one is within SHOPIFY_TOKEN_MARGIN of expiring. $force skips the
// cache and the failure backoff (used by the admin test page).
function shopify_admin_token(bool $force = false): string
{
    $fresh = function (?array $t): bool {
        return $t && !empty($t['access_token']) && (int)($t['expires_at'] ?? 0) - SHOPIFY_TOKEN_MARGIN > time();
    };
    if (!$force && $fresh($cached = shopify_cache_read('admin_token.json'))) {
        return $cached['access_token'];
    }

    return shopify_with_lock('admin_token', function () use ($force, $fresh) {
        // Another request may have refreshed it while we waited for the lock.
        if (!$force && $fresh($cached = shopify_cache_read('admin_token.json'))) {
            return $cached['access_token'];
        }
        $fail = shopify_cache_read('admin_token_error.json');
        if (!$force && $fail && time() - (int)$fail['at'] < SHOPIFY_AUTH_BACKOFF) {
            throw new ShopifyError((string)$fail['message']);
        }

        $clientId = (string)config('shopify.client_id');
        $secret = (string)config('shopify.client_secret');
        if ($clientId === '' || $secret === '') {
            throw new ShopifyError('Set shopify.client_id and shopify.client_secret in the config (Dev Dashboard > app > Settings).');
        }
        [$status, $json, $raw] = shopify_http(
            shopify_base() . '/admin/oauth/access_token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'client_credentials', 'client_id' => $clientId, 'client_secret' => $secret])
        );
        if ($status !== 200 || empty($json['access_token'])) {
            $message = shopify_auth_error($status, $json, $raw);
            shopify_cache_write('admin_token_error.json', ['at' => time(), 'message' => $message]);
            error_log('Shopify auth failed: ' . $message);
            throw new ShopifyError($message);
        }
        shopify_cache_delete('admin_token_error.json');
        $token = [
            'access_token' => (string)$json['access_token'],
            'scope'        => (string)($json['scope'] ?? ''),
            'expires_at'   => time() + (int)($json['expires_in'] ?? 86399),
            'fetched_at'   => time(),
        ];
        shopify_cache_write('admin_token.json', $token);
        return $token['access_token'];
    });
}

// Scopes and expiry of the cached Admin token, without the token itself.
function shopify_admin_token_info(): ?array
{
    $t = shopify_cache_read('admin_token.json');
    return $t ? ['scope' => $t['scope'] ?? '', 'expires_at' => (int)$t['expires_at'], 'fetched_at' => (int)($t['fetched_at'] ?? 0)] : null;
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
        // Token revoked early (secret rotated, app reinstalled). Drop it so the next call refreshes.
        shopify_cache_delete('admin_token.json');
    }
    return shopify_graphql_result($status, $json, $raw, 'Admin');
}

// Returns the Storefront token: from config, from the cache, or created once
// through the Admin API and then cached.
function shopify_storefront_token(): string
{
    $configured = trim((string)config('shopify.storefront_token', ''));
    if ($configured !== '') {
        return $configured;
    }
    $cached = shopify_cache_read('storefront_token.json');
    if (!empty($cached['access_token'])) {
        return $cached['access_token'];
    }
    return shopify_with_lock('storefront_token', function () {
        $cached = shopify_cache_read('storefront_token.json');
        if (!empty($cached['access_token'])) {
            return $cached['access_token'];
        }
        $data = shopify_admin_graphql(
            'mutation StorefrontAccessTokenCreate($input: StorefrontAccessTokenInput!) {
               storefrontAccessTokenCreate(input: $input) {
                 userErrors { field message }
                 storefrontAccessToken { accessToken title accessScopes { handle } }
               }
             }',
            ['input' => ['title' => 'discount-yarn.com']]
        );
        $result = $data['storefrontAccessTokenCreate'] ?? [];
        if (!empty($result['userErrors'])) {
            throw new ShopifyError('Could not create a Storefront token: ' . implode('; ', array_column($result['userErrors'], 'message')));
        }
        $tok = $result['storefrontAccessToken'] ?? null;
        if (empty($tok['accessToken'])) {
            throw new ShopifyError('Could not create a Storefront token: Shopify returned none.');
        }
        shopify_cache_write('storefront_token.json', [
            'access_token' => (string)$tok['accessToken'],
            'scopes'       => array_column((array)($tok['accessScopes'] ?? []), 'handle'),
            'created_at'   => time(),
        ]);
        return (string)$tok['accessToken'];
    });
}

function shopify_storefront_token_info(): array
{
    if (trim((string)config('shopify.storefront_token', '')) !== '') {
        return ['source' => 'config', 'scopes' => []];
    }
    $c = shopify_cache_read('storefront_token.json');
    return $c ? ['source' => 'cache', 'scopes' => (array)($c['scopes'] ?? []), 'created_at' => (int)$c['created_at']] : ['source' => 'none', 'scopes' => []];
}

function shopify_storefront_graphql(string $query, array $variables = []): array
{
    [$status, $json, $raw] = shopify_http(
        shopify_base() . '/api/' . shopify_api_version() . '/graphql.json',
        ['Content-Type: application/json', 'X-Shopify-Storefront-Access-Token: ' . shopify_storefront_token()],
        json_encode(['query' => $query, 'variables' => (object)$variables])
    );
    return shopify_graphql_result($status, $json, $raw, 'Storefront');
}

// Runs the connection checks used by the admin page and the CLI script.
// Returns a list of [label, ok, detail] rows. Never includes token values.
function shopify_connection_report(): array
{
    $rows = [];
    try {
        $rows[] = ['Shop domain', true, shopify_domain()];
        $dir = realpath(shopify_cache_dir()) ?: shopify_cache_dir();
        // The store folder is the document root on the server, also when run from the CLI.
        $docroot = realpath(__DIR__ . '/..') ?: '';
        $public = $docroot !== '' && str_starts_with($dir . '/', rtrim($docroot, '/') . '/');
        $rows[] = ['Cache folder', !$public, $dir . ($public ? ' (inside the web root: move it outside public_html)' : '')];
        $rows[] = ['Config file', true, CONFIG_FILE];
    } catch (ShopifyError $ex) {
        $rows[] = ['Setup', false, $ex->getMessage()];
        return $rows;
    }

    try {
        shopify_admin_token(true);
        $info = shopify_admin_token_info();
        $rows[] = ['Admin token', true, 'OK, expires ' . date('M j, g:ia T', $info['expires_at']) . '. Scopes: ' . ($info['scope'] ?: 'none reported')];
    } catch (ShopifyError $ex) {
        $rows[] = ['Admin token', false, $ex->getMessage()];
        return $rows;
    }

    try {
        $d = shopify_admin_graphql('{ shop { name myshopifyDomain primaryDomain { url } currencyCode }
            all: productsCount { count precision }
            active: productsCount(query: "status:active") { count precision } }');
        $shop = $d['shop'] ?? [];
        $fmt = fn($c) => (int)($c['count'] ?? 0) . (($c['precision'] ?? 'EXACT') !== 'EXACT' ? '+' : '');
        $rows[] = ['Shop (Admin API)', true, ($shop['name'] ?? '?') . ' (' . ($shop['primaryDomain']['url'] ?? $shop['myshopifyDomain'] ?? '') . ', ' . ($shop['currencyCode'] ?? '') . ')'];
        $rows[] = ['Products (Admin API)', true, $fmt($d['active'] ?? []) . ' active of ' . $fmt($d['all'] ?? []) . ' total'];
    } catch (ShopifyError $ex) {
        $rows[] = ['Admin API', false, $ex->getMessage()];
    }

    try {
        $d = shopify_storefront_graphql('{ shop { name } products(first: 1) { edges { node { title } } } }');
        $info = shopify_storefront_token_info();
        $first = $d['products']['edges'][0]['node']['title'] ?? null;
        $rows[] = ['Storefront API', true, 'Shop "' . ($d['shop']['name'] ?? '?') . '", token from ' . $info['source']
            . ($info['scopes'] ? ' (scopes: ' . implode(', ', $info['scopes']) . ')' : '')
            . ($first !== null ? '. First listed product: ' . $first : '. No products are published to this app\'s sales channel yet.')];
    } catch (ShopifyError $ex) {
        $rows[] = ['Storefront API', false, $ex->getMessage()];
    }
    return $rows;
}
