<?php
// Session cart: product id => quantity. Prices and stock are always re-read
// from the database, never trusted from the browser.

declare(strict_types=1);

const CART_MAX_QTY = 99;

function cart_raw(): array
{
    return isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? $_SESSION['cart'] : [];
}

function cart_set(int $productId, int $qty): void
{
    $cart = cart_raw();
    if ($qty <= 0) {
        unset($cart[$productId]);
    } else {
        $cart[$productId] = min($qty, CART_MAX_QTY);
    }
    $_SESSION['cart'] = $cart;
}

function cart_add(int $productId, int $qty): void
{
    cart_set($productId, (cart_raw()[$productId] ?? 0) + $qty);
}

function cart_clear(): void
{
    unset($_SESSION['cart']);
}

function cart_count(): int
{
    return array_sum(cart_raw());
}

// Returns the cart joined with live product rows. Items that are gone or
// inactive are dropped; quantities above stock are capped.
function cart_lines(): array
{
    $cart = cart_raw();
    if (!$cart) {
        return [];
    }
    $ids = array_map('intval', array_keys($cart));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT * FROM products WHERE is_active = 1 AND id IN ($in)");
    $st->execute($ids);
    $lines = [];
    $changed = false;
    foreach ($st->fetchAll() as $p) {
        $want = (int)$cart[$p['id']];
        $qty = min($want, max(0, (int)$p['stock']));
        if ($qty !== $want) {
            $changed = true;
        }
        if ($qty > 0) {
            $unit = effective_price($p);
            $lines[] = ['product' => $p, 'qty' => $qty, 'unit' => $unit, 'line' => $unit * $qty];
        }
    }
    $kept = [];
    foreach ($lines as $l) {
        $kept[(int)$l['product']['id']] = $l['qty'];
    }
    if ($changed || count($kept) !== count($cart)) {
        $_SESSION['cart'] = $kept;
        $_SESSION['cart_adjusted'] = true;
    }
    return $lines;
}

function cart_subtotal(array $lines): int
{
    return array_sum(array_column($lines, 'line'));
}

function shipping_for(int $subtotal): int
{
    $free = (int)config('shipping.free_over_cents', 0);
    if ($free > 0 && $subtotal >= $free) {
        return 0;
    }
    return (int)config('shipping.flat_rate_cents', 0);
}
