<?php
// Stripe webhook endpoint. In the Stripe dashboard (Leafwood account) add an
// endpoint for https://<store domain>/webhook.php with these events:
//   checkout.session.completed
//   checkout.session.async_payment_succeeded
//   checkout.session.expired
// Sessions from Leafwood's other payments are ignored (no store metadata).

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/cart.php';
require __DIR__ . '/includes/orders.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$payload = file_get_contents('php://input') ?: '';
$event = stripe_verify_webhook($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', (string)config('stripe.webhook_secret'));
if ($event === null) {
    http_response_code(400);
    exit('Bad signature');
}

$pdo = db();
try {
    $pdo->prepare('INSERT INTO webhook_events (event_id, type) VALUES (?, ?)')->execute([$event['id'], $event['type']]);
} catch (PDOException $ex) {
    if ($ex->getCode() !== '23000') {
        throw $ex;
    }
    // Already handled this event.
    http_response_code(200);
    exit('Duplicate');
}

try {
    $session = $event['data']['object'] ?? [];
    switch ($event['type']) {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            fulfill_from_session($session);
            break;
        case 'checkout.session.expired':
            expire_from_session($session);
            break;
    }
} catch (Throwable $ex) {
    // Let Stripe retry.
    $pdo->prepare('DELETE FROM webhook_events WHERE event_id = ?')->execute([$event['id']]);
    error_log('Webhook failed for ' . $event['id'] . ': ' . $ex->getMessage());
    http_response_code(500);
    exit('Error');
}

http_response_code(200);
echo 'OK';
