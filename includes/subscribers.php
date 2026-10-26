<?php
// The email list: double opt-in sign-up, confirmation, unsubscribe, and
// sending blasts through Postmark's broadcast stream.

declare(strict_types=1);

require_once __DIR__ . '/postmark.php';

const SIGNUP_LIMIT_PER_HOUR = 5;
const BLAST_BATCH = 500;   // Postmark's batch maximum

function subscriber_email_ok(string $email): bool
{
    return strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function subscriber_by(string $column, string $value): ?array
{
    if (!in_array($column, ['email', 'token'], true)) {
        return null;
    }
    $st = db()->prepare("SELECT * FROM subscribers WHERE $column = ?");
    $st->execute([$value]);
    return $st->fetch() ?: null;
}

// Throttles sign-ups per visitor (a daily hash, no IP stored). True when allowed.
function signup_allowed(): bool
{
    $who = substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . date('Y-m-d') . '|' . __FILE__), 0, 16);
    $pdo = db();
    $pdo->prepare('DELETE FROM signup_attempts WHERE created_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM signup_attempts WHERE who = ? AND created_at >= ?');
    $st->execute([$who, date('Y-m-d H:i:s', time() - 3600)]);
    if ((int)$st->fetchColumn() >= SIGNUP_LIMIT_PER_HOUR) {
        return false;
    }
    $pdo->prepare('INSERT INTO signup_attempts (who, created_at) VALUES (?, ?)')->execute([$who, date('Y-m-d H:i:s')]);
    return true;
}

// Adds (or re-invites) someone as pending and sends the confirmation email.
// Already-confirmed people are left alone. Returns 'sent', 'already' or 'failed'.
function subscriber_request(string $email, string $firstName, string $source): string
{
    $email = strtolower(trim($email));
    $existing = subscriber_by('email', $email);
    if ($existing && $existing['status'] === 'confirmed') {
        return 'already';
    }
    $token = bin2hex(random_bytes(16));
    $now = date('Y-m-d H:i:s');
    if ($existing) {
        db()->prepare("UPDATE subscribers SET status = 'pending', token = ?, first_name = CASE WHEN ? <> '' THEN ? ELSE first_name END, confirm_sent_at = ?, unsubscribed_at = NULL WHERE id = ?")
            ->execute([$token, $firstName, $firstName, $now, (int)$existing['id']]);
    } else {
        db()->prepare("INSERT INTO subscribers (email, first_name, status, token, source, created_at, confirm_sent_at) VALUES (?, ?, 'pending', ?, ?, ?, ?)")
            ->execute([$email, mb_substr($firstName, 0, 100), $token, $source, $now, $now]);
    }
    return send_confirmation($email, $firstName, $token, $source) ? 'sent' : 'failed';
}

// Adds someone straight to the list as confirmed, without a confirmation
// email, for people who agreed some other way (in person, on a form,
// through another list). The note records how. Someone who unsubscribed is
// never added back. Returns 'added', 'confirmed' (was pending), 'already'
// or 'unsubscribed'.
function subscriber_add_confirmed(string $email, string $firstName, string $note): string
{
    $email = strtolower(trim($email));
    $now = date('Y-m-d H:i:s');
    $note = mb_substr(trim($note), 0, 255);
    $existing = subscriber_by('email', $email);
    if ($existing) {
        if ($existing['status'] === 'unsubscribed') {
            return 'unsubscribed';
        }
        if ($existing['status'] === 'confirmed') {
            return 'already';
        }
        db()->prepare("UPDATE subscribers SET status = 'confirmed', confirmed_at = ?, consent_note = ?, first_name = CASE WHEN ? <> '' THEN ? ELSE first_name END WHERE id = ?")
            ->execute([$now, $note, $firstName, $firstName, (int)$existing['id']]);
        return 'confirmed';
    }
    db()->prepare("INSERT INTO subscribers (email, first_name, status, token, source, created_at, confirmed_at, consent_note) VALUES (?, ?, 'confirmed', ?, 'manual', ?, ?, ?)")
        ->execute([$email, mb_substr($firstName, 0, 100), bin2hex(random_bytes(16)), $now, $now, $note]);
    return 'added';
}

function send_confirmation(string $email, string $firstName, string $token, string $source): bool
{
    $store = (string)config('store_name');
    $link = url('confirm.php?t=' . $token);
    $hi = $firstName !== '' ? 'Hi ' . $firstName . ',' : 'Hi,';
    $intro = $source === 'invite'
        ? 'We\'re starting an email list for ' . $store . ', where we post name-brand yarn below its usual price. Would you like to be on it?'
        : 'Thanks for signing up for email from ' . $store . '.';
    $html = email_wrap('Please confirm your email',
        '<p>' . e($hi) . '</p><p>' . e($intro) . '</p>'
        . '<p><a href="' . e($link) . '" style="display:inline-block;background:#2f5d50;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none">Yes, add me to the list</a></p>'
        . '<p style="font-size:13px;color:#7a6f66">If you didn\'t ask for this, ignore this email and you won\'t hear from us again.</p>');
    $text = "$hi\n\n$intro\n\nConfirm here: $link\n\nIf you didn't ask for this, ignore this email and you won't hear from us again.";
    [$html, $text] = email_with_utm($html, $text, 'list-confirm', $source === 'invite' ? 'invite' : 'signup');
    return postmark_send($email, 'Please confirm your email for ' . $store, $html, $text, 'list-confirm');
}

function subscriber_confirm(string $token): ?array
{
    $s = preg_match('/^[a-f0-9]{32}$/', $token) ? subscriber_by('token', $token) : null;
    if ($s && $s['status'] === 'pending') {
        db()->prepare("UPDATE subscribers SET status = 'confirmed', confirmed_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int)$s['id']]);
        $s = subscriber_by('token', $token);
    }
    return $s;
}

function subscriber_unsubscribe(string $token): ?array
{
    $s = preg_match('/^[a-f0-9]{32}$/', $token) ? subscriber_by('token', $token) : null;
    if ($s && $s['status'] !== 'unsubscribed') {
        db()->prepare("UPDATE subscribers SET status = 'unsubscribed', unsubscribed_at = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int)$s['id']]);
        $s = subscriber_by('token', $token);
    }
    return $s;
}

// A product card for a blast: the product page's own photo, name, current
// price (including any discount tier) and a Shop now button. Returns
// [html, text], or null when the product isn't live.
function blast_product_card(string $slug): ?array
{
    require_once __DIR__ . '/catalog.php';
    $variants = catalog_group_variants($slug);
    if (!$variants) {
        return null;
    }
    $p = $variants[0];   // in-stock colorways come first
    $prices = array_map('effective_price', $variants);
    $low = min($prices);
    $high = max($prices);
    $regular = max(array_map(fn($v) => (int)$v['price_cents'], $variants));
    $off = max(array_map('percent_off', $variants));
    $link = url(group_url($slug));
    $img = product_image($p, 1120);
    if (!str_starts_with($img, 'http')) {
        $img = url($img);
    }
    $price = ($low !== $high ? 'From ' : '') . money($low);
    $was = $off > 0 ? money($regular) : '';
    $inStock = (int)$p['stock'] > 0;
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 22px;border:1px solid #e6dccb;border-radius:6px;border-collapse:separate;overflow:hidden">'
        . '<tr><td style="padding:0"><a href="' . e($link) . '"><img src="' . e($img) . '" alt="' . e($p['name']) . '" width="560" style="display:block;width:100%;max-width:560px;height:auto;border:0"></a></td></tr>'
        . '<tr><td style="padding:16px 18px;font-family:Arial,sans-serif">'
        . ($p['brand'] !== '' ? '<p style="margin:0;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#7a6f66">' . e($p['brand']) . '</p>' : '')
        . '<p style="margin:2px 0 6px;font-family:Georgia,serif;font-size:20px;color:#2f2a26">' . e($p['name']) . '</p>'
        . '<p style="margin:0 0 14px;font-size:17px">'
        . ($was !== '' ? '<s style="color:#7a6f66">' . e($was) . '</s> ' : '')
        . '<strong style="color:' . ($off > 0 ? '#a63d40' : '#2f2a26') . '">' . e($price) . '</strong>'
        . ($off > 0 ? ' <span style="font-size:13px;color:#a63d40">' . ($low !== $high || $off !== min(array_map('percent_off', $variants)) ? 'up to ' : '') . $off . '% off</span>' : '')
        . (count($variants) > 1 ? '<br><span style="font-size:13px;color:#7a6f66">' . count($variants) . ' colorways</span>' : '')
        . '</p>'
        . '<a href="' . e($link) . '" style="display:inline-block;background:#2f5d50;color:#ffffff;padding:11px 20px;border-radius:6px;text-decoration:none;font-weight:bold">' . ($inStock ? 'Shop now' : 'See it') . '</a>'
        . '</td></tr></table>';
    $text = $p['name'] . ($p['brand'] !== '' ? ' by ' . $p['brand'] : '') . ': ' . $price . ($was !== '' ? ' (was ' . $was . ')' : '') . "\n" . $link;
    return [$html, $text];
}

// The yarns for a [lastchance] block: the named product addresses, or with
// none named, the four in-stock yarns with the fewest skeins left. Returns [slug => variants] for the ones that are live.
function blast_lastchance_groups(string $slugs): array
{
    require_once __DIR__ . '/catalog.php';
    $slugs = array_values(array_filter(array_map('trim', explode(',', $slugs))));
    if (!$slugs) {
        $slugs = db()->query("SELECT group_slug FROM products WHERE is_active = 1 AND category = 'yarn' AND group_slug <> ''
            GROUP BY group_slug HAVING SUM(CASE WHEN stock > 0 THEN stock ELSE 0 END) > 0
            ORDER BY SUM(CASE WHEN stock > 0 THEN stock ELSE 0 END), group_slug LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
    }
    $groups = [];
    foreach ($slugs as $slug) {
        $variants = catalog_group_variants($slug);
        if ($variants) {
            $groups[$slug] = $variants;
        }
    }
    return $groups;
}

// A bright two-by-two grid of yarns with how many skeins are left and a row
// of colorway photos that each open that colorway. The cards sit side by
// side on wide screens and stack on phones without needing CSS media queries.
function blast_lastchance(string $slugs): array
{
    $cards = '';
    $text = [];
    foreach (blast_lastchance_groups($slugs) as $slug => $variants) {
        $p = $variants[0];
        $inStock = array_values(array_filter($variants, fn($v) => (int)$v['stock'] > 0));
        $left = array_sum(array_map(fn($v) => (int)$v['stock'], $inStock));
        $low = min(array_map('effective_price', $variants));
        $regular = max(array_map(fn($v) => (int)$v['price_cents'], $variants));
        $off = max(array_map('percent_off', $variants));
        $link = url(group_url($slug));
        $img = product_image($p, 560);
        $img = str_starts_with($img, 'http') ? $img : url($img);
        $badge = $left === 0 ? 'Sold out' : ($left === 1 ? 'Last skein' : 'Only ' . $left . ' left');
        $swatches = '';
        foreach (array_slice($inStock, 0, 5) as $v) {
            $src = product_image($v, 120);
            $src = str_starts_with($src, 'http') ? $src : url($src);
            $swatches .= '<a href="' . e(url(group_url($slug, (int)$v['id']))) . '" style="display:inline-block;margin:0 4px 4px 0"><img src="' . e($src) . '" alt="' . e($v['colorway'] !== '' ? $v['colorway'] : $v['name']) . ', ' . (int)$v['stock'] . ' left" title="' . e($v['colorway']) . '" width="38" height="38" style="display:block;width:38px;height:38px;border-radius:50%;border:2px solid #ffffff;box-shadow:0 0 0 1px #e6dccb;object-fit:cover"></a>';
        }
        $more = count($inStock) - 5;
        $cards .= '<div style="display:inline-block;vertical-align:top;width:100%;max-width:252px;margin:0 4px 14px;text-align:left">'
            . '<div style="background:#ffffff;border-radius:10px;overflow:hidden;border:2px solid #f2c94c">'
            . '<div style="background:' . ($left > 0 ? '#e8504f' : '#7a6f66') . ';color:#ffffff;font:bold 13px/1 Arial,sans-serif;letter-spacing:.06em;text-transform:uppercase;padding:9px 12px">' . e($badge) . ($off > 0 ? '<span style="float:right;background:#f2c94c;color:#2f2a26;border-radius:10px;padding:2px 8px;margin-top:-2px">' . $off . '% off</span>' : '') . '</div>'
            . '<a href="' . e($link) . '"><img src="' . e($img) . '" alt="' . e($p['name']) . '" width="252" style="display:block;width:100%;height:auto;border:0"></a>'
            . '<div style="padding:12px 14px 16px;font-family:Arial,sans-serif">'
            . ($p['brand'] !== '' ? '<p style="margin:0;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#7a6f66">' . e($p['brand']) . '</p>' : '')
            . '<p style="margin:2px 0 6px;font-family:Georgia,serif;font-size:18px;line-height:1.25;color:#2f2a26">' . e($p['name']) . '</p>'
            . '<p style="margin:0 0 10px;font-size:16px">' . ($off > 0 ? '<s style="color:#7a6f66">' . money($regular) . '</s> ' : '') . '<strong style="color:#c0392b">' . money($low) . '</strong></p>'
            . ($swatches !== '' ? '<p style="margin:0 0 4px;font-size:12px;color:#7a6f66">Tap a color:</p><div style="margin:0 0 10px">' . $swatches . ($more > 0 ? '<a href="' . e($link) . '" style="font-size:12px;color:#2f5d50;vertical-align:top;line-height:40px">+' . $more . ' more</a>' : '') . '</div>' : '')
            . '<a href="' . e($link) . '" style="display:block;text-align:center;background:#2f5d50;color:#ffffff;padding:11px 0;border-radius:6px;text-decoration:none;font-weight:bold">' . ($left > 0 ? 'Grab it before it\'s gone' : 'See it') . '</a>'
            . '</div></div></div>';
        $text[] = $p['name'] . ($p['brand'] !== '' ? ' by ' . $p['brand'] : '') . ': ' . money($low) . ($off > 0 ? ' (was ' . money($regular) . ')' : '') . ', ' . strtolower($badge) . "\n" . $link;
    }
    if ($cards === '') {
        return ['', ''];
    }
    $html = '<div style="margin:6px -8px 18px;padding:18px 4px 4px;background:#fff4d6;border-radius:10px;text-align:center">' . $cards
        . '<p style="margin:0 0 16px"><a href="' . e(url('shop.php?sale=1')) . '" style="display:inline-block;background:#e8504f;color:#ffffff;padding:12px 22px;border-radius:24px;text-decoration:none;font:bold 15px Arial,sans-serif">See everything on sale</a></p></div>';
    return [$html, implode("\n\n", $text) . "\n\nEverything on sale: " . url('shop.php?sale=1')];
}

// Problems that would make a blast look broken, such as an unknown product.
function blast_problems(string $body): array
{
    $problems = [];
    if (preg_match_all('/^\[product:([^\]]*)\]$/m', str_replace("\r\n", "\n", $body), $m)) {
        foreach ($m[1] as $slug) {
            $slug = trim($slug);
            if (blast_product_card($slug) === null) {
                $problems[] = 'No live product with the address "' . $slug . '". Use the part after slug= in the product page link.';
            }
        }
    }
    if (preg_match_all('/^\[lastchance(?::([^\]]*))?\]$/m', str_replace("\r\n", "\n", $body), $m)) {
        foreach ($m[1] as $list) {
            $named = array_values(array_filter(array_map('trim', explode(',', $list))));
            $found = array_keys(blast_lastchance_groups($list));
            foreach (array_diff($named, $found) as $slug) {
                $problems[] = 'No live product with the address "' . $slug . '". Use the part after slug= in the product page link.';
            }
            if (!$named && !$found) {
                $problems[] = 'There is no yarn in stock for the [lastchance] block.';
            }
        }
    }
    if (preg_match_all('/^\[image:([^\]|]*)/m', $body, $m)) {
        foreach ($m[1] as $src) {
            if (!preg_match('#^https://\S+$#', trim($src))) {
                $problems[] = 'Image addresses must start with https:// (' . trim($src) . ').';
            }
        }
    }
    return $problems;
}

// Plain text with blank-line paragraphs; URLs become links and {first_name}
// is filled in. A paragraph that is just [product:slug] becomes a product
// card, [image:https://...|alt text] a full-width image, [banner:Words] a
// bright headline strip and [lastchance] (or [lastchance:slug, slug, ...]) a
// grid of yarns with how much is left. Returns [html, text].
function blast_render(string $body, string $firstName): array
{
    $body = str_replace("\r\n", "\n", trim($body));
    $name = $firstName !== '' ? $firstName : 'there';
    $html = '';
    $text = [];
    foreach (preg_split("/\n{2,}/", $body) as $para) {
        $para = trim($para);
        if (preg_match('/^\[product:([^\]]+)\]$/', $para, $m)) {
            $card = blast_product_card(trim($m[1]));
            if ($card) {
                $html .= $card[0];
                $text[] = $card[1];
            }
            continue;
        }
        if (preg_match('/^\[lastchance(?::([^\]]*))?\]$/', $para, $m)) {
            [$h, $t] = blast_lastchance($m[1] ?? '');
            $html .= $h;
            $text[] = $t;
            continue;
        }
        if (preg_match('/^\[banner:([^\]]+)\]$/', $para, $m)) {
            $line = trim($m[1]);
            $html .= '<div style="margin:0 -8px 18px;background:#e8504f;border-radius:10px;padding:22px 18px;text-align:center;color:#ffffff;font-family:Georgia,serif;font-size:26px;line-height:1.25">'
                . e($line) . '<div style="width:60px;height:4px;background:#f2c94c;margin:12px auto 0;border-radius:2px"></div></div>';
            $text[] = strtoupper($line);
            continue;
        }
        if (preg_match('/^\[image:(https:\/\/[^\]|\s]+)(?:\|([^\]]*))?\]$/', $para, $m)) {
            $html .= '<p style="margin:0 0 18px"><img src="' . e($m[1]) . '" alt="' . e(trim($m[2] ?? '')) . '" width="560" style="display:block;width:100%;max-width:560px;height:auto;border:0;border-radius:6px"></p>';
            if (trim($m[2] ?? '') !== '') {
                $text[] = trim($m[2]);
            }
            continue;
        }
        $para = str_replace('{first_name}', $name, $para);
        $text[] = $para;
        $p = nl2br(e($para), false);
        $p = preg_replace('#https?://[^\s<]+[^\s<.,;:!?)\]]#', '<a href="$0" style="color:#2f5d50">$0</a>', $p);
        $html .= '<p style="margin:0 0 14px;line-height:1.55">' . $p . '</p>';
    }
    return [$html, implode("\n\n", $text)];
}

// $utmSource is 'newsletter' for real blasts and 'test-email' for tests and
// previews, so test clicks don't count toward the campaign.
function blast_message(string $subject, string $body, array $sub, string $utmSource = 'newsletter'): array
{
    $store = (string)config('store_name');
    $address = trim((string)config('mailing_address'));
    $unsub = url('unsubscribe.php?t=' . $sub['token']);
    [$html, $text] = blast_render($body, (string)$sub['first_name']);
    $foot = 'You\'re getting this because you joined the ' . $store . ' email list.';
    $html = '<div style="background:#f7f1e6;padding:24px;font-family:Georgia,serif;color:#2f2a26">'
        . '<div style="max-width:560px;margin:0 auto;background:#fffdf8;border:1px solid #e6dccb;border-radius:6px;padding:28px">'
        . '<p style="margin:0 0 18px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#2f5d50">' . e($store) . '</p>'
        . $html . '</div>'
        . '<p style="max-width:560px;margin:16px auto 0;font-size:12px;color:#7a6f66;font-family:Arial,sans-serif;text-align:center">'
        . e($foot) . '<br>' . e($address) . '<br><a href="' . e($unsub) . '" style="color:#7a6f66">Unsubscribe</a></p></div>';
    $text .= "\n\n--\n$foot\n$address\nUnsubscribe: $unsub";
    [$html, $text] = email_with_utm($html, $text, $utmSource, email_campaign($subject));
    return [
        'From'          => config('postmark.from'),
        'To'            => $sub['email'],
        'ReplyTo'       => config('support_email'),
        'Subject'       => $subject,
        'HtmlBody'      => $html,
        'TextBody'      => $text,
        'MessageStream' => config('postmark.broadcast_stream', 'broadcast'),
        'Tag'           => 'blast',
        'Headers'       => [
            ['Name' => 'List-Unsubscribe', 'Value' => '<' . $unsub . '>'],
            ['Name' => 'List-Unsubscribe-Post', 'Value' => 'List-Unsubscribe=One-Click'],
        ],
    ];
}

// Why a blast can't be sent yet, or '' when it can.
function blast_blocker(): string
{
    if (trim((string)config('mailing_address')) === '') {
        return 'Set mailing_address in the config. US law (CAN-SPAM) requires a postal address in every marketing email.';
    }
    $token = (string)config('postmark.server_token');
    if ($token === '' || $token === 'CHANGE_ME') {
        return 'Set postmark.server_token in the config.';
    }
    return '';
}

// Sends to a list of subscriber rows in batches. Returns [sent, failed, error].
function blast_send_to(string $subject, string $body, array $subs, string $utmSource = 'newsletter'): array
{
    $sent = $failed = 0;
    $error = '';
    foreach (array_chunk($subs, BLAST_BATCH) as $chunk) {
        [$s, $f, $err] = postmark_batch(array_map(fn($sub) => blast_message($subject, $body, $sub, $utmSource), $chunk));
        $sent += $s;
        $failed += $f;
        $error = $error ?: $err;
    }
    return [$sent, $failed, $error];
}
