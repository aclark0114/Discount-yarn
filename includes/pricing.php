<?php
// Discount tiers for yarn synced from Shopify. A product's tier comes from
// its own rule, else its brand's, else the store default. The shopper pays
// Shopify's price less that percent; the higher of Shopify's price and
// compare-at price is shown struck through.

declare(strict_types=1);

// The tiers offered in admin, from config. 0 means "no discount".
function discount_buckets(): array
{
    $b = array_map('intval', (array)config('discount_buckets', [0, 10, 25, 50]));
    $b[] = 0;
    $b = array_values(array_unique(array_filter($b, fn($p) => $p >= 0 && $p <= 90)));
    sort($b);
    return $b;
}

function price_rules(bool $reload = false): array
{
    static $rules = null;
    if ($rules === null || $reload) {
        $rules = ['default' => [], 'brand' => [], 'product' => []];
        foreach (db()->query('SELECT scope, match_value, percent FROM price_rules')->fetchAll() as $r) {
            $rules[$r['scope']][$r['match_value']] = (int)$r['percent'];
        }
    }
    return $rules;
}

// Returns [percent, where it came from].
function discount_for(string $brand, string $shopifyProductId): array
{
    $r = price_rules();
    if ($shopifyProductId !== '' && isset($r['product'][$shopifyProductId])) {
        return [$r['product'][$shopifyProductId], 'product'];
    }
    if (isset($r['brand'][$brand])) {
        return [$r['brand'][$brand], 'brand'];
    }
    return [(int)($r['default'][''] ?? 0), 'default'];
}

// Returns [price_cents, sale_price_cents|null] for the products table.
function tier_prices(int $shopifyPrice, ?int $compareAt, int $percent): array
{
    $regular = max($shopifyPrice, (int)$compareAt);
    $ours = (int)round($shopifyPrice * (100 - max(0, min(90, $percent))) / 100);
    return [max(1, $regular), $ours > 0 && $ours < $regular ? $ours : null];
}

function set_price_rule(string $scope, string $match, ?int $percent): void
{
    if (!in_array($scope, ['default', 'brand', 'product'], true)) {
        throw new InvalidArgumentException('Unknown price rule scope');
    }
    db()->prepare('DELETE FROM price_rules WHERE scope = ? AND match_value = ?')->execute([$scope, $match]);
    if ($percent !== null) {
        db()->prepare('INSERT INTO price_rules (scope, match_value, percent) VALUES (?, ?, ?)')->execute([$scope, $match, $percent]);
    }
    price_rules(true);
}

// Re-applies tiers to every Shopify row. Returns how many prices changed.
function reprice_shopify_rows(): int
{
    $pdo = db();
    $upd = $pdo->prepare('UPDATE products SET price_cents = ?, sale_price_cents = ? WHERE id = ?');
    $n = 0;
    $rows = $pdo->query('SELECT id, brand, shopify_product_id, shopify_price_cents, shopify_compare_cents, price_cents, sale_price_cents
                         FROM products WHERE shopify_variant_id IS NOT NULL AND shopify_price_cents IS NOT NULL')->fetchAll();
    foreach ($rows as $r) {
        [$pct] = discount_for((string)$r['brand'], (string)$r['shopify_product_id']);
        [$price, $sale] = tier_prices((int)$r['shopify_price_cents'], $r['shopify_compare_cents'] === null ? null : (int)$r['shopify_compare_cents'], $pct);
        if ($price !== (int)$r['price_cents'] || $sale !== ($r['sale_price_cents'] === null ? null : (int)$r['sale_price_cents'])) {
            $upd->execute([$price, $sale, (int)$r['id']]);
            $n++;
        }
    }
    return $n;
}
