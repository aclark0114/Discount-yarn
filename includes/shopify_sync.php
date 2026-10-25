<?php
// Mirrors the Stars Hollow Shopify catalog into the products table, one row
// per variant, so store pages and Stripe checkout read only the local
// database. Run by cron/shopify_sync.php and the admin Sync now button.

declare(strict_types=1);

require_once __DIR__ . '/shopify.php';
require_once __DIR__ . '/products.php';

const SHOPIFY_SYNC_PAGE = 8;       // products per request; keeps query cost under Shopify's 1,000 limit
const SHOPIFY_SYNC_VARIANTS = 50;  // variants per product per request; more are paged

const SHOPIFY_VARIANT_FIELDS = 'id sku title price compareAtPrice inventoryQuantity availableForSale
    selectedOptions { name value }
    media(first: 1) { nodes { ... on MediaImage { image { url } } } }';

// Admin GraphQL with a short wait-and-retry when Shopify throttles us.
function shopify_sync_query(string $query, array $variables): array
{
    for ($try = 1; ; $try++) {
        try {
            return shopify_admin_graphql($query, $variables);
        } catch (ShopifyError $ex) {
            if ($try >= 5 || stripos($ex->getMessage(), 'throttled') === false) {
                throw $ex;
            }
            sleep(2 * $try);
        }
    }
}

function shopify_money_cents($amount): ?int
{
    if ($amount === null || $amount === '') {
        return null;
    }
    $amount = is_array($amount) ? ($amount['amount'] ?? null) : $amount;
    return $amount === null ? null : (int)round((float)$amount * 100);
}

// Finds a yarn weight in the product type, tags or variant options.
function shopify_guess_weight(array $product, array $variant): string
{
    $aliases = [
        'Super Bulky' => ['super bulky', 'super chunky', 'jumbo'],
        'Bulky'       => ['bulky', 'chunky'],
        'Aran'        => ['aran'],
        'Worsted'     => ['worsted'],
        'DK'          => ['dk', 'double knit', 'light worsted'],
        'Sport'       => ['sport'],
        'Fingering'   => ['fingering', 'sock', 'super fine'],
        'Lace'        => ['lace', 'cobweb'],
    ];
    $hay = [(string)($product['productType'] ?? '')];
    foreach ((array)($product['tags'] ?? []) as $t) {
        $hay[] = (string)$t;
    }
    foreach ((array)($variant['selectedOptions'] ?? []) as $o) {
        if (stripos((string)$o['name'], 'weight') !== false) {
            $hay[] = (string)$o['value'];
        }
    }
    $text = ' ' . strtolower(implode(' | ', $hay)) . ' ';
    foreach ($aliases as $weight => $words) {
        foreach ($words as $w) {
            if (preg_match('/(^|[^a-z])' . preg_quote($w, '/') . '([^a-z]|$)/', $text)) {
                return $weight;
            }
        }
    }
    return '';
}

// A tag like "100% merino" or "fiber: alpaca", or a Fiber option.
function shopify_guess_fiber(array $product, array $variant): string
{
    foreach ((array)($variant['selectedOptions'] ?? []) as $o) {
        if (stripos((string)$o['name'], 'fiber') !== false || stripos((string)$o['name'], 'fibre') !== false) {
            return (string)$o['value'];
        }
    }
    foreach ((array)($product['tags'] ?? []) as $t) {
        $t = trim((string)$t);
        if (preg_match('/^fib(er|re)\s*[:\-]\s*(.+)$/i', $t, $m)) {
            return $m[2];
        }
        if (str_contains($t, '%')) {
            return $t;
        }
    }
    return '';
}

// Column values for one variant. Prices: the higher of Shopify's price and
// compare-at price is the "was" price; Shopify's price less the configured
// markdown is what shoppers pay.
function shopify_variant_row(array $product, array $variant, int $heldBack): array
{
    $price = (int)shopify_money_cents($variant['price']);
    $compare = shopify_money_cents($variant['compareAtPrice'] ?? null);
    $markdown = max(0, min(90, (float)config('shopify.markdown_percent', 0)));
    $regular = max($price, (int)$compare);
    $ours = (int)round($price * (100 - $markdown) / 100);

    $vt = trim((string)$variant['title']);
    $colorway = $vt === 'Default Title' ? '' : $vt;
    $image = $variant['media']['nodes'][0]['image']['url']
        ?? $product['featuredMedia']['preview']['image']['url']
        ?? null;
    $stock = $variant['inventoryQuantity'] === null ? 0 : (int)$variant['inventoryQuantity'];
    if (empty($variant['availableForSale'])) {
        $stock = 0;
    }

    $sku = trim((string)$variant['sku']);
    $fallback = 'SH-' . preg_replace('/\D/', '', (string)$variant['id']);
    if ($sku === '') {
        $sku = $fallback;
    } else {
        $other = product_by_sku($sku);
        if ($other && (string)$other['shopify_variant_id'] !== (string)$variant['id']) {
            $sku = $fallback;  // SKU already used by another row
        }
    }

    return [
        'sku'                => mb_substr($sku, 0, 64),
        'name'               => mb_substr((string)$product['title'], 0, 200),
        'brand'              => mb_substr((string)$product['vendor'], 0, 120),
        'weight'             => shopify_guess_weight($product, $variant),
        'fiber'              => mb_substr(shopify_guess_fiber($product, $variant), 0, 160),
        'colorway'           => mb_substr($colorway, 0, 120),
        'description'        => (string)($product['description'] ?? ''),
        'price_cents'        => max(1, $regular),
        'sale_price_cents'   => $ours > 0 && $ours < $regular ? $ours : null,
        'stock'              => max(0, $stock - $heldBack),
        'is_active'          => 1,
        'shopify_product_id' => (string)$product['id'],
        'shopify_variant_id' => (string)$variant['id'],
        'image_url'          => $image,
        'synced_at'          => date('Y-m-d H:i:s'),
    ];
}

// Units in paid orders that haven't shipped yet. Shopify doesn't know about
// them, so they are held back from the Shopify count until they ship.
function shopify_units_held(): array
{
    $rows = db()->query(
        "SELECT p.shopify_variant_id AS v, SUM(i.quantity) AS q
         FROM order_items i JOIN orders o ON o.id = i.order_id JOIN products p ON p.id = i.product_id
         WHERE o.status = 'paid' AND p.shopify_variant_id IS NOT NULL
         GROUP BY p.shopify_variant_id"
    )->fetchAll();
    return array_column($rows, 'q', 'v');
}

// Runs a full sync. Returns stats. Only one sync runs at a time.
function shopify_sync_catalog(): array
{
    return shopify_with_lock('catalog_sync', function () {
        $started = time();
        $stats = ['products' => 0, 'variants' => 0, 'added' => 0, 'updated' => 0, 'hidden' => 0, 'seconds' => 0];
        $held = shopify_units_held();
        $seen = [];
        $pdo = db();
        $find = $pdo->prepare('SELECT * FROM products WHERE shopify_variant_id = ?');

        $query = 'query Catalog($cursor: String) {
          products(first: ' . SHOPIFY_SYNC_PAGE . ', after: $cursor, query: "status:active") {
            pageInfo { hasNextPage endCursor }
            nodes {
              id title handle vendor productType tags description createdAt
              featuredMedia { preview { image { url } } }
              variants(first: ' . SHOPIFY_SYNC_VARIANTS . ') {
                pageInfo { hasNextPage endCursor }
                nodes { ' . SHOPIFY_VARIANT_FIELDS . ' }
              }
            }
          }
        }';
        $moreVariants = 'query MoreVariants($id: ID!, $cursor: String) {
          product(id: $id) {
            variants(first: ' . SHOPIFY_SYNC_VARIANTS . ', after: $cursor) {
              pageInfo { hasNextPage endCursor }
              nodes { ' . SHOPIFY_VARIANT_FIELDS . ' }
            }
          }
        }';

        $cursor = null;
        do {
            $data = shopify_sync_query($query, ['cursor' => $cursor]);
            $page = $data['products'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]];
            foreach ($page['nodes'] as $product) {
                $stats['products']++;
                $variants = $product['variants']['nodes'] ?? [];
                $vInfo = $product['variants']['pageInfo'] ?? ['hasNextPage' => false];
                while (!empty($vInfo['hasNextPage'])) {
                    $more = shopify_sync_query($moreVariants, ['id' => $product['id'], 'cursor' => $vInfo['endCursor']]);
                    $variants = array_merge($variants, $more['product']['variants']['nodes'] ?? []);
                    $vInfo = $more['product']['variants']['pageInfo'] ?? ['hasNextPage' => false];
                }
                foreach ($variants as $variant) {
                    $stats['variants']++;
                    $vid = (string)$variant['id'];
                    $seen[$vid] = true;
                    $find->execute([$vid]);
                    $existing = $find->fetch() ?: null;
                    $row = shopify_variant_row($product, $variant, (int)($held[$vid] ?? 0));
                    if ($existing) {
                        // Keep admin-only choices made on this site.
                        $row['is_featured'] = (int)$existing['is_featured'];
                    }
                    save_product($row, $existing ? (int)$existing['id'] : null);
                    $stats[$existing ? 'updated' : 'added']++;
                }
            }
            $cursor = $page['pageInfo']['endCursor'] ?? null;
        } while (!empty($page['pageInfo']['hasNextPage']));

        // Anything from Shopify that wasn't in this sync is gone, archived or a draft.
        $hide = $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ?');
        foreach ($pdo->query('SELECT id, shopify_variant_id FROM products WHERE shopify_variant_id IS NOT NULL AND is_active = 1')->fetchAll() as $r) {
            if (!isset($seen[$r['shopify_variant_id']])) {
                $hide->execute([(int)$r['id']]);
                $stats['hidden']++;
            }
        }

        $stats['seconds'] = time() - $started;
        $stats['finished_at'] = time();
        shopify_cache_write('catalog_sync.json', $stats);
        return $stats;
    });
}

function shopify_last_sync(): ?array
{
    try {
        return shopify_cache_read('catalog_sync.json');
    } catch (ShopifyError $ex) {
        return null;
    }
}

// Checks cart lines against Shopify right before checkout. Updates the
// local stock and price for those variants and returns true when anything
// the shopper would pay or receive changed. Returns false if Shopify can't
// be reached, so checkout falls back to the last sync.
function shopify_revalidate_lines(array $lines): bool
{
    $ids = [];
    foreach ($lines as $l) {
        if (!empty($l['product']['shopify_variant_id'])) {
            $ids[] = (string)$l['product']['shopify_variant_id'];
        }
    }
    if (!$ids) {
        return false;
    }
    try {
        $data = shopify_admin_graphql(
            'query Live($ids: [ID!]!) { nodes(ids: $ids) { ... on ProductVariant {
               id price compareAtPrice inventoryQuantity availableForSale } } }',
            ['ids' => $ids]
        );
    } catch (ShopifyError $ex) {
        error_log('Live stock check skipped: ' . $ex->getMessage());
        return false;
    }
    $live = [];
    foreach ((array)($data['nodes'] ?? []) as $n) {
        if (is_array($n) && isset($n['id'])) {
            $live[(string)$n['id']] = $n;
        }
    }
    $held = shopify_units_held();
    $changed = false;
    $upd = db()->prepare('UPDATE products SET stock = ?, price_cents = ?, sale_price_cents = ?, is_active = ? WHERE id = ?');
    foreach ($lines as $l) {
        $p = $l['product'];
        $vid = (string)($p['shopify_variant_id'] ?? '');
        if ($vid === '') {
            continue;
        }
        $n = $live[$vid] ?? null;
        if ($n === null) {
            // Deleted in Shopify since the last sync.
            $upd->execute([0, (int)$p['price_cents'], $p['sale_price_cents'], 0, (int)$p['id']]);
            $changed = true;
            continue;
        }
        $row = shopify_variant_row(
            ['id' => $p['shopify_product_id'], 'title' => $p['name'], 'vendor' => $p['brand']],
            $n + ['title' => 'Default Title', 'sku' => $p['sku']],
            (int)($held[$vid] ?? 0)
        );
        $upd->execute([$row['stock'], $row['price_cents'], $row['sale_price_cents'], 1, (int)$p['id']]);
        $newUnit = $row['sale_price_cents'] ?? $row['price_cents'];
        if ($row['stock'] < $l['qty'] || $newUnit !== $l['unit']) {
            $changed = true;
        }
    }
    return $changed;
}
