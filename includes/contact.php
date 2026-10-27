<?php
// The contact form: the message goes to support_email (hello@), with replies
// going straight back to the sender, and the sender gets a short receipt.

declare(strict_types=1);

const CONTACT_LIMIT_PER_HOUR = 5;
const CONTACT_TOPICS = [
    'sell'  => 'Selling yarn: my shop has stock to clear',
    'order' => 'A question about an order',
    'yarn'  => 'A question about a yarn',
    'other' => 'Something else',
];

// At most CONTACT_LIMIT_PER_HOUR messages an hour from one visitor. Shares
// the sign-up throttle table with a different, daily-changing visitor hash.
function contact_allowed(): bool
{
    $who = substr(hash('sha256', 'contact|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . date('Y-m-d') . '|' . __FILE__), 0, 16);
    $pdo = db();
    $pdo->prepare('DELETE FROM signup_attempts WHERE created_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM signup_attempts WHERE who = ? AND created_at >= ?');
    $st->execute([$who, date('Y-m-d H:i:s', time() - 3600)]);
    if ((int)$st->fetchColumn() >= CONTACT_LIMIT_PER_HOUR) {
        return false;
    }
    $pdo->prepare('INSERT INTO signup_attempts (who, created_at) VALUES (?, ?)')->execute([$who, date('Y-m-d H:i:s')]);
    return true;
}

// Sends the message to the store and the receipt to the sender. Returns
// false if the store's copy couldn't be sent.
function contact_send(array $m): bool
{
    $store = (string)config('store_name');
    $topic = CONTACT_TOPICS[$m['topic']] ?? CONTACT_TOPICS['other'];
    $details = ['Name' => $m['name'], 'Email' => $m['email'], 'Shop' => $m['shop'], 'Phone' => $m['phone'], 'About' => $topic];
    $rowsHtml = '';
    $rowsText = '';
    foreach (array_filter($details, fn($v) => $v !== '') as $label => $value) {
        $rowsHtml .= '<tr><td style="padding:3px 12px 3px 0;color:#7a6f66;vertical-align:top">' . $label . '</td><td style="padding:3px 0">' . e($value) . '</td></tr>';
        $rowsText .= "$label: $value\n";
    }
    $html = email_wrap($m['topic'] === 'sell' ? 'A shop wants to sell us yarn' : 'New message from the website',
        '<table style="border-collapse:collapse;font-size:15px;margin:0 0 16px">' . $rowsHtml . '</table>'
        . '<div style="border-left:3px solid #e6dccb;padding:4px 0 4px 14px;white-space:pre-wrap">' . e($m['message']) . '</div>'
        . '<p style="margin:16px 0 0;font-size:13px;color:#7a6f66">Reply to this email to answer ' . e($m['name']) . ' directly.</p>');
    $text = $rowsText . "\n" . $m['message'] . "\n\nReply to this email to answer {$m['name']} directly.";
    $subject = ($m['topic'] === 'sell' ? 'Yarn to sell: ' : 'Website message: ') . ($m['shop'] !== '' ? $m['shop'] . ' (' . $m['name'] . ')' : $m['name']);
    if (!postmark_send((string)config('support_email'), $subject, $html, $text, 'contact-form', $m['email'])) {
        return false;
    }

    // The receipt doesn't repeat the message, so the form can't be used to
    // send someone else text of a stranger's choosing.
    $first = trim(explode(' ', $m['name'])[0]);
    $intro = $m['topic'] === 'sell'
        ? 'Thanks for getting in touch about your yarn. We\'ll look over what you sent and get back to you soon, usually with a few questions about brands, quantities and colors.'
        : 'Thanks for getting in touch. We got your message and a real person will reply soon.';
    $receiptHtml = email_wrap('We got your message',
        '<p>Hi ' . e($first) . ',</p><p>' . e($intro) . '</p>'
        . '<p>If you need to add anything, just reply to this email.</p>'
        . '<p style="margin-top:20px">The ' . e($store) . ' team</p>');
    $receiptText = "Hi $first,\n\n$intro\n\nIf you need to add anything, just reply to this email.\n\nThe $store team";
    postmark_send($m['email'], 'We got your message | ' . $store, $receiptHtml, $receiptText, 'contact-receipt');
    return true;
}
