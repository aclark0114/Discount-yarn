<?php
// First-party visitor activity. Page views arrive from a small beacon in
// assets/script.js (so bots that don't run JavaScript aren't counted);
// add-to-cart and checkout are logged by the server. No IP addresses or
// cookies are stored: a visitor is a hash of IP, browser and date that
// changes every day. Logged-in admins are never counted.

declare(strict_types=1);

const ACTIVITY_BOT_RE = '/bot|crawl|spider|slurp|preview|headless|monitor|curl|wget|python|scrapy|lighthouse/i';
const ACTIVITY_KEEP_DAYS = 180;

function activity_visitor(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return substr(hash('sha256', $ip . '|' . $ua . '|' . date('Y-m-d') . '|' . __FILE__), 0, 16);
}

function activity_device(): string
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (preg_match('/iPad|Tablet/i', $ua)) {
        return 'tablet';
    }
    return preg_match('/Mobi|Android|iPhone/i', $ua) ? 'mobile' : 'desktop';
}

function activity_skip(): bool
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $ua === '' || preg_match(ACTIVITY_BOT_RE, $ua) === 1 || !empty($_SESSION['admin']);
}

// Records one event. Never throws: activity must not break a page.
function activity_log(string $event, array $fields = []): void
{
    if (PHP_SAPI === 'cli' || activity_skip()) {
        return;
    }
    try {
        db()->prepare(
            'INSERT INTO site_events (created_at, visitor, event, path, product, value_cents, referrer_host, utm_source, utm_medium, utm_campaign, device)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            date('Y-m-d H:i:s'),
            activity_visitor(),
            $event,
            mb_substr((string)($fields['path'] ?? ''), 0, 200),
            isset($fields['product']) ? mb_substr((string)$fields['product'], 0, 160) : null,
            isset($fields['value_cents']) ? (int)$fields['value_cents'] : null,
            $fields['referrer_host'] ?? null,
            $fields['utm_source'] ?? null,
            $fields['utm_medium'] ?? null,
            $fields['utm_campaign'] ?? null,
            activity_device(),
        ]);
    } catch (Throwable $ex) {
        error_log('Activity log skipped: ' . $ex->getMessage());
    }
}

function activity_prune(): int
{
    $st = db()->prepare('DELETE FROM site_events WHERE created_at < ?');
    $st->execute([date('Y-m-d H:i:s', time() - ACTIVITY_KEEP_DAYS * 86400)]);
    return $st->rowCount();
}
