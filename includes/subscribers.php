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
// card, and [image:https://...|alt text] a full-width image. Returns [html, text].
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

function blast_message(string $subject, string $body, array $sub): array
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
function blast_send_to(string $subject, string $body, array $subs): array
{
    $sent = $failed = 0;
    $error = '';
    foreach (array_chunk($subs, BLAST_BATCH) as $chunk) {
        [$s, $f, $err] = postmark_batch(array_map(fn($sub) => blast_message($subject, $body, $sub), $chunk));
        $sent += $s;
        $failed += $f;
        $error = $error ?: $err;
    }
    return [$sent, $failed, $error];
}
