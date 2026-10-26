<?php
// Sends transactional email through this store's Postmark server.

declare(strict_types=1);

function postmark_send(string $to, string $subject, string $html, string $text, ?string $tag = null): bool
{
    $token = (string)config('postmark.server_token');
    if ($token === '' || $token === 'CHANGE_ME') {
        error_log('Postmark not configured; skipped email "' . $subject . '" to ' . $to);
        return false;
    }
    $payload = [
        'From'          => config('postmark.from'),
        'To'            => $to,
        'ReplyTo'       => config('support_email'),
        'Subject'       => $subject,
        'HtmlBody'      => $html,
        'TextBody'      => $text,
        'MessageStream' => config('postmark.message_stream', 'outbound'),
    ];
    if ($tag) {
        $payload['Tag'] = $tag;
    }
    $ch = curl_init(rtrim(getenv('POSTMARK_TEST_BASE') ?: 'https://api.postmarkapp.com', '/') . '/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Postmark-Server-Token: ' . $token,
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        error_log('Postmark send failed (' . $status . '): ' . (is_string($body) ? $body : ''));
        return false;
    }
    return true;
}

// Sends up to 500 messages in one Postmark batch call. Each message is a
// Postmark email array (From, To, Subject, HtmlBody, TextBody, ...).
// Returns [sent, failed, first error message or ''].
function postmark_batch(array $messages): array
{
    $token = (string)config('postmark.server_token');
    if ($token === '' || $token === 'CHANGE_ME') {
        return [0, count($messages), 'Postmark is not configured (postmark.server_token).'];
    }
    if (!$messages) {
        return [0, 0, ''];
    }
    $ch = curl_init(rtrim(getenv('POSTMARK_TEST_BASE') ?: 'https://api.postmarkapp.com', '/') . '/email/batch');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(array_values($messages)),
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Postmark-Server-Token: ' . $token,
        ],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $results = json_decode(is_string($body) ? $body : '', true);
    if ($status !== 200 || !is_array($results)) {
        $msg = is_array($results) ? (string)($results['Message'] ?? '') : '';
        error_log('Postmark batch failed (' . $status . '): ' . $msg);
        return [0, count($messages), 'Postmark refused the batch (HTTP ' . $status . ($msg !== '' ? ': ' . $msg : '') . ').'];
    }
    $sent = 0;
    $error = '';
    foreach ($results as $r) {
        if ((int)($r['ErrorCode'] ?? 1) === 0) {
            $sent++;
        } elseif ($error === '') {
            $error = (string)($r['Message'] ?? 'unknown error');
        }
    }
    return [$sent, count($messages) - $sent, $error];
}

function order_items_for(int $orderId): array
{
    $st = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
    $st->execute([$orderId]);
    return $st->fetchAll();
}

// Builds the item table shared by the order emails. Returns [html, text].
function order_summary(array $order): array
{
    $rowsHtml = '';
    $rowsText = '';
    foreach (order_items_for((int)$order['id']) as $it) {
        $line = (int)$it['unit_price_cents'] * (int)$it['quantity'];
        $rowsHtml .= '<tr><td style="padding:6px 0">' . e($it['name']) . ' &times; ' . (int)$it['quantity']
            . '</td><td style="padding:6px 0;text-align:right">' . money($line) . '</td></tr>';
        $rowsText .= $it['name'] . ' x ' . (int)$it['quantity'] . '  ' . money($line) . "\n";
    }
    $totals = [
        'Subtotal' => (int)$order['subtotal_cents'],
        'Shipping' => (int)$order['shipping_cents'],
    ];
    if ((int)$order['tax_cents'] > 0) {
        $totals['Tax'] = (int)$order['tax_cents'];
    }
    $totals['Total'] = (int)$order['total_cents'];
    foreach ($totals as $label => $cents) {
        $rowsHtml .= '<tr><td style="padding:4px 0;color:#5b5049">' . $label . '</td><td style="padding:4px 0;text-align:right">' . money($cents) . '</td></tr>';
        $rowsText .= $label . ': ' . money($cents) . "\n";
    }
    $html = '<table style="width:100%;border-collapse:collapse;font-size:15px">' . $rowsHtml . '</table>';
    return [$html, $rowsText];
}

function email_wrap(string $heading, string $inner): string
{
    $store = e(config('store_name'));
    return '<div style="background:#f7f1e6;padding:24px;font-family:Georgia,serif;color:#2f2a26">'
        . '<div style="max-width:560px;margin:0 auto;background:#fffdf8;border:1px solid #e6dccb;border-radius:6px;padding:28px">'
        . '<p style="margin:0 0 4px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#2f5d50">' . $store . '</p>'
        . '<h1 style="margin:0 0 16px;font-size:24px;font-weight:normal">' . e($heading) . '</h1>'
        . $inner
        . '<p style="margin:24px 0 0;font-size:13px;color:#7a6f66">Questions? Just reply to this email.</p>'
        . '</div></div>';
}

function send_order_confirmation(array $order): void
{
    [$tableHtml, $tableText] = order_summary($order);
    $ref = strtoupper($order['public_id']);
    $name = trim((string)$order['name']) ?: 'there';
    $html = email_wrap('Thanks for your order!',
        '<p>Hi ' . e($name) . ', we got your order <strong>#' . e($ref) . '</strong> and are winding it up now. '
        . 'We will email you again when it ships.</p>' . $tableHtml);
    $text = "Hi $name,\n\nThanks for your order #$ref. We will email you again when it ships.\n\n$tableText";
    postmark_send((string)$order['email'], 'Your ' . config('store_name') . ' order #' . $ref, $html, $text, 'order-confirmation');

    $owner = (string)config('owner_email');
    if ($owner !== '') {
        $flag = (int)$order['oversold'] ? '<p style="color:#9b2c2c"><strong>Heads up:</strong> at least one item was oversold. Check stock before packing.</p>' : '';
        $adminHtml = email_wrap('New order #' . $ref,
            '<p>' . e($order['name']) . ' &lt;' . e($order['email']) . '&gt;</p>' . $flag . $tableHtml
            . '<p><a href="' . e(url('admin/order.php?id=' . (int)$order['id'])) . '">Open in admin</a></p>');
        postmark_send($owner, 'New order #' . $ref . ' (' . money((int)$order['total_cents']) . ')', $adminHtml,
            "New order #$ref from {$order['name']} <{$order['email']}>\n\n$tableText", 'order-owner');
    }
}

function send_shipping_notice(array $order): bool
{
    $ref = strtoupper($order['public_id']);
    $name = trim((string)$order['name']) ?: 'there';
    $tracking = trim((string)$order['tracking_number']);
    $trackHtml = $tracking !== '' ? '<p>Tracking number: <strong>' . e($tracking) . '</strong></p>' : '';
    $trackText = $tracking !== '' ? "Tracking number: $tracking\n" : '';
    $html = email_wrap('Your yarn is on its way',
        '<p>Hi ' . e($name) . ', order <strong>#' . e($ref) . '</strong> has shipped.</p>' . $trackHtml);
    return postmark_send((string)$order['email'], 'Order #' . $ref . ' has shipped', $html,
        "Hi $name,\n\nOrder #$ref has shipped.\n$trackText", 'order-shipped');
}
