<?php
// "Under construction" mode. Turned on and off in admin > Store status; the
// switch is a small file next to the config (outside public_html), so it
// survives deploys. While it's on, shoppers get a "back soon" page with a 503
// (search engines treat that as temporary), and a logged-in admin sees the
// real store with a reminder bar. Stripe's webhook, the Shopify connection,
// the email list pages and the order success page keep working.

declare(strict_types=1);

const MAINTENANCE_OPEN_PATHS = ['/webhook.php', '/success.php', '/subscribe.php', '/confirm.php', '/unsubscribe.php', '/track.php'];

function maintenance_file(): string
{
    return dirname(CONFIG_FILE) . '/discount-yarn-maintenance.json';
}

// The current setting: null when the store is open, otherwise
// ['message' => ..., 'since' => 'Y-m-d H:i:s'].
function maintenance_state(): ?array
{
    static $state = false;
    if ($state === false) {
        $state = null;
        if (is_file(maintenance_file())) {
            $data = json_decode((string)file_get_contents(maintenance_file()), true);
            $state = ['message' => (string)($data['message'] ?? ''), 'since' => (string)($data['since'] ?? '')];
        } elseif (config('maintenance')) {
            // Fallback switch in the config, for when admin can't be reached.
            $state = ['message' => '', 'since' => ''];
        }
    }
    return $state;
}

function maintenance_set(bool $on, string $message = ''): bool
{
    if (!$on) {
        return !is_file(maintenance_file()) || unlink(maintenance_file());
    }
    $json = json_encode(['message' => mb_substr(trim($message), 0, 500), 'since' => date('Y-m-d H:i:s')]);
    return file_put_contents(maintenance_file(), $json, LOCK_EX) !== false;
}

function maintenance_gate(): void
{
    if (PHP_SAPI === 'cli' || maintenance_state() === null || !empty($_SESSION['admin'])) {
        return;
    }
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (in_array($path, MAINTENANCE_OPEN_PATHS, true) || $path === '/admin' || str_starts_with($path, '/admin/') || str_starts_with($path, '/shopify/')) {
        return;
    }
    maintenance_page();
    exit;
}

function maintenance_page(): void
{
    $store = (string)config('store_name');
    $message = maintenance_state()['message'] ?: 'We\'re making some changes to the store and will be back soon. Leave your email and we\'ll let you know when the shelves are open again.';
    $logo = is_file(__DIR__ . '/../assets/logo.png');
    http_response_code(503);
    header('Retry-After: 3600');
    header('Cache-Control: no-store');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Back soon | <?= e($store) ?></title>
<meta name="robots" content="noindex">
<link rel="icon" href="/assets/favicon-32.png" sizes="32x32" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lora:wght@400;600&family=Nunito+Sans:wght@400;700&display=swap" rel="stylesheet">
<style>
  :root { --cream: #f7f1e6; --paper: #fffdf8; --ink: #2f2a26; --muted: #6f645b; --line: #e6dccb; --evergreen: #2f5d50; --berry: #a63d40; --mustard: #d9a441; --r: 6px; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--cream); color: var(--ink); font: 16px/1.6 "Nunito Sans", system-ui, sans-serif; }
  main { width: 100%; max-width: 520px; background: var(--paper); border: 1px solid var(--line); border-radius: var(--r); padding: 36px 28px; text-align: center; }
  img { max-width: 220px; height: auto; }
  .name { font: 600 24px "Lora", Georgia, serif; margin: 0; }
  .tag { display: inline-block; margin: 22px 0 4px; padding: 4px 12px; border-radius: 999px; background: var(--mustard); color: var(--ink); font-size: 13px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
  h1 { font: 400 30px/1.25 "Lora", Georgia, serif; margin: 8px 0 12px; }
  p { margin: 0 0 20px; color: var(--muted); }
  form { display: flex; flex-wrap: wrap; gap: 8px; }
  input[type=email] { flex: 1 1 220px; min-width: 0; padding: 11px 12px; border: 1px solid var(--line); border-radius: var(--r); font: inherit; }
  button { flex: 0 0 auto; padding: 11px 18px; border: 0; border-radius: var(--r); background: var(--evergreen); color: #fff; font: 700 15px inherit; font-family: inherit; cursor: pointer; }
  button:hover { background: #234a3f; }
  .hp { position: absolute; left: -9999px; }
  .contact { margin: 22px 0 0; font-size: 14px; }
  .contact a { color: var(--evergreen); }
  label.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
</style>
</head>
<body>
<main>
  <?php if ($logo): ?><img src="/assets/logo.png" alt="<?= e($store) ?>" width="220"><?php else: ?><p class="name"><?= e($store) ?></p><?php endif; ?>
  <div><span class="tag">Under construction</span></div>
  <h1>We'll be back soon</h1>
  <p><?= nl2br(e($message), false) ?></p>
  <form method="post" action="/subscribe.php">
    <?= csrf_field() ?>
    <label class="sr" for="mt-email">Email</label>
    <input id="mt-email" name="email" type="email" autocomplete="email" placeholder="you@example.com" required>
    <button type="submit">Tell me when you're back</button>
    <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
  </form>
  <p class="contact">Questions about an order? <a href="mailto:<?= e(config('support_email')) ?>"><?= e(config('support_email')) ?></a></p>
</main>
</body>
</html>
<?php
}
