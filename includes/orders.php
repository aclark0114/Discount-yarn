<?php
// Order creation and fulfillment shared by checkout, the Stripe webhook and
// the success page.

declare(strict_types=1);

require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/postmark.php';
require_once __DIR__ . '/shopify_sync.php';

// Tags every Checkout Session so the webhook can ignore the Leafwood
// account's other payments.
const STORE_METADATA_KEY = 'discount-yarn';

function order_by_public_id(string $publicId): ?array
{
    $st = db()->prepare('SELECT * FROM orders WHERE public_id = ?');
    $st->execute([$publicId]);
    return $st->fetch() ?: null;
}

function order_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// Saves a pending order for the cart and returns its row.
function create_pending_order(array $lines): array
{
    $pdo = db();
    $subtotal = cart_subtotal($lines);
    $publicId = bin2hex(random_bytes(8));
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO orders (public_id, status, subtotal_cents, shipping_cents, total_cents) VALUES (?, ?, ?, ?, ?)')
        ->execute([$publicId, 'pending', $subtotal, shipping_for($subtotal), $subtotal + shipping_for($subtotal)]);
    $orderId = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, sku, name, unit_price_cents, quantity) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($lines as $l) {
        $p = $l['product'];
        $name = $p['name'] . ($p['colorway'] !== '' ? ' (' . $p['colorway'] . ')' : '');
        $ins->execute([$orderId, (int)$p['id'], $p['sku'], $name, $l['unit'], $l['qty']]);
    }
    $pdo->commit();
    return order_by_id($orderId);
}

function create_checkout_session(array $order, array $lines): array
{
    $params = [
        'mode'                => 'payment',
        'client_reference_id' => $order['public_id'],
        'success_url'         => url('success.php?order=' . $order['public_id'] . '&session_id={CHECKOUT_SESSION_ID}'),
        'cancel_url'          => url('cart.php'),
        'expires_at'          => time() + 3600,
        'metadata'            => ['store' => STORE_METADATA_KEY, 'order' => $order['public_id']],
        'payment_intent_data' => [
            'metadata'    => ['store' => STORE_METADATA_KEY, 'order' => $order['public_id']],
            'description' => config('store_name') . ' order #' . strtoupper($order['public_id']),
        ],
        'shipping_address_collection' => ['allowed_countries' => config('shipping.countries', ['US'])],
        'phone_number_collection'     => ['enabled' => 'false'],
        'line_items'          => [],
    ];
    $suffix = trim((string)config('stripe.statement_descriptor_suffix', ''));
    if ($suffix !== '') {
        $params['payment_intent_data']['statement_descriptor_suffix'] = substr($suffix, 0, 22);
    }
    foreach ($lines as $l) {
        $p = $l['product'];
        $params['line_items'][] = [
            'quantity'   => $l['qty'],
            'price_data' => [
                'currency'     => 'usd',
                'unit_amount'  => $l['unit'],
                'product_data' => [
                    'name'     => $p['name'] . ($p['colorway'] !== '' ? ' (' . $p['colorway'] . ')' : ''),
                    'metadata' => ['sku' => $p['sku']],
                ],
            ],
        ];
    }
    $shipping = (int)$order['shipping_cents'];
    $params['shipping_options'] = [[
        'shipping_rate_data' => [
            'type'         => 'fixed_amount',
            'display_name' => $shipping === 0 ? 'Free shipping' : 'Standard shipping',
            'fixed_amount' => ['amount' => $shipping, 'currency' => 'usd'],
            'delivery_estimate' => [
                'minimum' => ['unit' => 'business_day', 'value' => 3],
                'maximum' => ['unit' => 'business_day', 'value' => 7],
            ],
        ],
    ]];
    if (config('stripe.automatic_tax')) {
        $params['automatic_tax'] = ['enabled' => 'true'];
    }

    $session = stripe_request('POST', 'checkout/sessions', $params);
    db()->prepare('UPDATE orders SET stripe_session_id = ? WHERE id = ?')->execute([$session['id'], $order['id']]);
    return $session;
}

// Marks the order paid from a completed Checkout Session, takes the stock
// and sends the emails. Safe to call more than once for the same session.
function fulfill_from_session(array $session): ?array
{
    if (($session['metadata']['store'] ?? '') !== STORE_METADATA_KEY) {
        return null;
    }
    if (($session['payment_status'] ?? '') !== 'paid') {
        return null;
    }
    $pdo = db();
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM orders WHERE stripe_session_id = ?' . $lock);
        $st->execute([$session['id']]);
        $order = $st->fetch();
        if (!$order || !in_array($order['status'], ['pending', 'expired'], true)) {
            $pdo->commit();
            return null;
        }

        $customer = $session['customer_details'] ?? [];
        // Newer API versions move shipping under collected_information.
        $ship = $session['collected_information']['shipping_details'] ?? $session['shipping_details'] ?? null;
        $address = '';
        if (is_array($ship)) {
            $a = $ship['address'] ?? [];
            $address = implode("\n", array_filter([
                $ship['name'] ?? '',
                $a['line1'] ?? '',
                $a['line2'] ?? '',
                trim(($a['city'] ?? '') . ', ' . ($a['state'] ?? '') . ' ' . ($a['postal_code'] ?? ''), ', '),
                $a['country'] ?? '',
            ]));
        }
        $totals = $session['total_details'] ?? [];

        $oversold = 0;
        $take = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
        $check = $pdo->prepare('SELECT stock FROM products WHERE id = ?');
        foreach (order_items_for((int)$order['id']) as $it) {
            if ($it['product_id'] === null) {
                continue;
            }
            $take->execute([(int)$it['quantity'], (int)$it['product_id']]);
            $check->execute([(int)$it['product_id']]);
            if ((int)$check->fetchColumn() < 0) {
                $oversold = 1;
            }
        }

        $pdo->prepare(
            'UPDATE orders SET status = ?, email = ?, name = ?, shipping_address = ?, stripe_payment_intent = ?,
             subtotal_cents = ?, shipping_cents = ?, tax_cents = ?, total_cents = ?, oversold = ?, paid_at = ?
             WHERE id = ?'
        )->execute([
            'paid',
            (string)($customer['email'] ?? ''),
            (string)($ship['name'] ?? $customer['name'] ?? ''),
            $address,
            is_string($session['payment_intent'] ?? null) ? $session['payment_intent'] : null,
            (int)($session['amount_subtotal'] ?? $order['subtotal_cents']),
            (int)($totals['amount_shipping'] ?? $order['shipping_cents']),
            (int)($totals['amount_tax'] ?? 0),
            (int)($session['amount_total'] ?? $order['total_cents']),
            $oversold,
            date('Y-m-d H:i:s'),
            (int)$order['id'],
        ]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    $order = order_by_id((int)$order['id']);
    try {
        send_order_confirmation($order);
    } catch (Throwable $ex) {
        error_log('Order email failed: ' . $ex->getMessage());
    }
    // Take the skeins out of Shopify. A failure is recorded on the order and
    // retried by the sync cron; until then the skeins are held back here.
    try {
        shopify_push_order_inventory((int)$order['id']);
    } catch (Throwable $ex) {
        error_log('Shopify stock push failed: ' . $ex->getMessage());
    }
    return order_by_id((int)$order['id']);
}

function expire_from_session(array $session): void
{
    if (($session['metadata']['store'] ?? '') !== STORE_METADATA_KEY) {
        return;
    }
    db()->prepare("UPDATE orders SET status = 'expired' WHERE stripe_session_id = ? AND status = 'pending'")
        ->execute([$session['id']]);
}
