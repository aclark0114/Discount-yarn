<?php
// Loaded at the top of every page. Sets up config, the database, the session
// and small helpers shared across the store and admin.

declare(strict_types=1);

// Preferred: outside the web root, e.g. /home/<cpaneluser>/discount-yarn-config.php
// (this file lives in /home/<cpaneluser>/public_html/discount-yarn.com/includes).
const CONFIG_OUTSIDE_WEBROOT = __DIR__ . '/../../../discount-yarn-config.php';
// Fallback: config.php in the document root, blocked by .htaccess.
const CONFIG_FALLBACK = __DIR__ . '/../config.php';

$configFile = getenv('STORE_CONFIG') ?: (is_file(CONFIG_OUTSIDE_WEBROOT) ? CONFIG_OUTSIDE_WEBROOT : CONFIG_FALLBACK);
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Store is not configured yet. Copy config.sample.php to discount-yarn-config.php in your home folder.');
}
$CONFIG = require $configFile;
date_default_timezone_set((string)($CONFIG['timezone'] ?? 'America/New_York'));
define('CONFIG_FILE', realpath($configFile) ?: $configFile);

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function config(string $key, $default = null)
{
    global $CONFIG;
    $value = $CONFIG;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(config('db.dsn'), config('db.user'), config('db.pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

function url(string $path = ''): string
{
    return rtrim((string)config('base_url'), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Your session expired. Go back, refresh the page and try again.');
    }
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

// The price a shopper pays: the sale price when one is set and lower.
function effective_price(array $p): int
{
    $sale = $p['sale_price_cents'] ?? null;
    if ($sale !== null && (int)$sale > 0 && (int)$sale < (int)$p['price_cents']) {
        return (int)$sale;
    }
    return (int)$p['price_cents'];
}

function percent_off(array $p): int
{
    $price = (int)$p['price_cents'];
    $now = effective_price($p);
    return $price > 0 && $now < $price ? (int)round(100 * ($price - $now) / $price) : 0;
}

// Shopify CDN images resize on request with ?width=.
function sized_image(string $url, int $width): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'width=' . $width;
}

// Photo URL: a Shopify CDN image (sized with ?width=), an uploaded file, or the placeholder.
function product_image(array $p, int $width = 800): string
{
    if (!empty($p['image_url'])) {
        return sized_image((string)$p['image_url'], $width);
    }
    return !empty($p['image']) ? '/uploads/' . rawurlencode($p['image']) : '/assets/yarn-placeholder.svg';
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim((string)$text, '-') ?: 'item';
}

const YARN_WEIGHTS = ['Lace', 'Fingering', 'Sport', 'DK', 'Worsted', 'Aran', 'Bulky', 'Super Bulky'];
