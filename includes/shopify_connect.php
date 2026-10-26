<?php
// Shared by /shopify/install.php and /shopify/callback.php: the page frame
// and the admin password gate used once the store is connected.

declare(strict_types=1);

require_once __DIR__ . '/shopify.php';

// How long a correct admin password allows a reconnect, in seconds.
const SHOPIFY_RECONNECT_WINDOW = 900;

function shopify_connect_page(string $title, string $html, int $status = 200): void
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . e($title) . ' | ' . e(config('store_name')) . '</title>'
        . '<link rel="stylesheet" href="/assets/admin.css?v=9"></head><body class="login-page"><div class="login">'
        . '<h1>' . e($title) . '</h1>' . $html . '</div></body></html>';
    exit;
}

// True when this session supplied the admin password recently.
function shopify_reconnect_allowed(): bool
{
    return (int)($_SESSION['shopify_reconnect_until'] ?? 0) > time();
}

// While the connection lock exists, stop unless this session has supplied
// the admin password from config. Shows (and handles) the password form
// when $offerForm is true; otherwise just refuses.
function shopify_require_unlock(bool $offerForm): void
{
    if (!shopify_connect_locked() || shopify_reconnect_allowed()) {
        return;
    }
    if (!$offerForm) {
        shopify_connect_page('Already connected', '<p>The store is already connected to Shopify. To reconnect, start again from <a href="/shopify/install.php">/shopify/install.php</a> and enter the admin password.</p>', 403);
    }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        sleep(1);
        require_once __DIR__ . '/admin_password.php';
        $fails = (int)($_SESSION['shopify_reconnect_fails'] ?? 0);
        if ($fails < 10 && admin_password_verify((string)($_POST['password'] ?? ''))) {
            unset($_SESSION['shopify_reconnect_fails']);
            $_SESSION['shopify_reconnect_until'] = time() + SHOPIFY_RECONNECT_WINDOW;
            return;
        }
        $_SESSION['shopify_reconnect_fails'] = $fails + 1;
        $error = $fails >= 10 ? 'Too many attempts. Close the browser and try again later.' : 'That password did not match.';
    }
    shopify_connect_page('Reconnect Shopify',
        '<p>The store is already connected to Shopify. Enter the admin password to connect again.</p>'
        . ($error !== '' ? '<p class="error">' . e($error) . '</p>' : '')
        . '<form method="post">' . csrf_field()
        . '<label for="password">Admin password</label><input id="password" name="password" type="password" autocomplete="current-password" required>'
        . '<button type="submit">Continue</button></form>', 403);
}
