<?php
// Minimal Stripe API client over curl, so the store needs no Composer install
// on cPanel. Card data never touches this server: shoppers pay in Stripe's
// Embedded Checkout form on our checkout page (or Stripe's hosted page).

declare(strict_types=1);

// Keys from config with stray spaces, line breaks or quotes from copy and paste removed.
function stripe_key(string $name): string
{
    return trim((string)config('stripe.' . $name, ''), " \t\n\r\0\x0B\"'");
}

function stripe_request(string $method, string $path, array $params = []): array
{
    // Local tests point this at a mock server. Never set on the live site.
    $base = rtrim(getenv('STRIPE_TEST_BASE') ?: 'https://api.stripe.com', '/') . '/v1/';
    $ch = curl_init($base . ltrim($path, '/'));
    $headers = ['Stripe-Version: 2024-06-20'];
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, '', '&'));
        // Retries of the same request body are safe.
        $headers[] = 'Idempotency-Key: ' . hash('sha256', $path . serialize($params));
    } elseif ($params) {
        curl_setopt($ch, CURLOPT_URL, $base . ltrim($path, '/') . '?' . http_build_query($params));
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => stripe_key('secret_key') . ':',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Stripe request failed: ' . $error);
    }
    $data = json_decode((string)$body, true);
    if ($status >= 400 || !is_array($data)) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $status);
        $param = $data['error']['param'] ?? '';
        throw new RuntimeException('Stripe error: ' . $msg . ($param !== '' ? ' (field: ' . $param . ')' : ''));
    }
    return $data;
}

// Checks the Stripe-Signature header and returns the decoded event, or null.
function stripe_verify_webhook(string $payload, string $sigHeader, string $secret, int $tolerance = 300): ?array
{
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') {
            $timestamp = (int)$v;
        } elseif ($k === 'v1') {
            $signatures[] = $v;
        }
    }
    if (!$timestamp || !$signatures || abs(time() - $timestamp) > $tolerance) {
        return null;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            $event = json_decode($payload, true);
            return is_array($event) ? $event : null;
        }
    }
    return null;
}
