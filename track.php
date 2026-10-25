<?php
// Page view beacon from assets/script.js. Always answers 204 with no body,
// and never breaks a page.

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/activity.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
http_response_code(204);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    exit;
}

// Same-site requests only.
$own = strtolower((string)parse_url((string)config('base_url'), PHP_URL_HOST));
$own = preg_replace('/^www\./', '', $own);
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $oh = preg_replace('/^www\./', '', strtolower((string)parse_url($origin, PHP_URL_HOST)));
    if ($oh !== $own) {
        exit;
    }
}

$path = (string)($_POST['p'] ?? '');
if (!preg_match('#^/[A-Za-z0-9/_.\-]{0,199}$#', $path) || str_starts_with($path, '/admin') || str_starts_with($path, '/shopify')) {
    exit;
}

parse_str(substr((string)($_POST['q'] ?? ''), 0, 400), $qs);
$tag = function (string $key) use ($qs): ?string {
    if (!isset($qs[$key]) || !is_string($qs[$key])) {
        return null;
    }
    $v = mb_substr(strtolower(trim($qs[$key])), 0, 100);
    return $v === '' ? null : $v;
};

$product = null;
if ($path === '/product.php' && isset($qs['slug']) && is_string($qs['slug']) && preg_match('/^[a-z0-9-]{1,160}$/', $qs['slug'])) {
    $product = $qs['slug'];
}

$refHost = preg_replace('/^www\./', '', strtolower((string)parse_url((string)($_POST['r'] ?? ''), PHP_URL_HOST)));
$refHost = ($refHost === '' || $refHost === $own) ? null : mb_substr($refHost, 0, 120);

activity_log('view', [
    'path'          => $path,
    'product'       => $product,
    'referrer_host' => $refHost,
    'utm_source'    => $tag('utm_source'),
    'utm_medium'    => $tag('utm_medium'),
    'utm_campaign'  => $tag('utm_campaign'),
]);
