<?php
// Product validation and saving, shared by the admin form and CSV import.

declare(strict_types=1);

const PRODUCT_FIELDS = ['sku', 'name', 'brand', 'weight', 'fiber', 'colorway', 'yardage', 'grams', 'description',
    'price', 'sale_price', 'stock', 'is_active', 'is_featured', 'image'];

// Parses "12.50", "$12.50" or "12" into cents. Returns null for blank.
function parse_cents($value): ?int
{
    $value = trim(str_replace(['$', ','], '', (string)$value));
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
        throw new InvalidArgumentException('"' . $value . '" is not a valid price');
    }
    return (int)round((float)$value * 100);
}

function parse_bool($value, bool $default): bool
{
    $v = strtolower(trim((string)$value));
    if ($v === '') {
        return $default;
    }
    return in_array($v, ['1', 'yes', 'y', 'true', 'on', 'live'], true);
}

function normalize_weight(string $weight): string
{
    $weight = trim($weight);
    foreach (YARN_WEIGHTS as $w) {
        if (strcasecmp($w, $weight) === 0) {
            return $w;
        }
    }
    return $weight;
}

// Turns raw input (form or CSV row) into a column array. Throws on bad data.
function product_from_input(array $in, ?array $existing = null): array
{
    $get = fn(string $k) => array_key_exists($k, $in) ? trim((string)$in[$k]) : null;
    $keep = fn(string $k, $fallback = '') => $existing[$k] ?? $fallback;

    $name = $get('name') ?? $keep('name');
    $sku = $get('sku') ?? $keep('sku');
    if ($name === '') {
        throw new InvalidArgumentException('Name is required');
    }
    if ($sku === '') {
        throw new InvalidArgumentException('SKU is required');
    }
    $price = $get('price') !== null ? parse_cents($get('price')) : ($existing ? (int)$existing['price_cents'] : null);
    if ($price === null || $price <= 0) {
        throw new InvalidArgumentException('Price is required');
    }
    $sale = $get('sale_price') !== null ? parse_cents($get('sale_price')) : ($existing['sale_price_cents'] ?? null);
    if ($sale !== null && ($sale <= 0 || $sale >= $price)) {
        $sale = null;
    }
    $int = function (string $k) use ($get, $existing) {
        $v = $get($k);
        if ($v === null) {
            return $existing[$k] ?? null;
        }
        return $v === '' ? null : max(0, (int)$v);
    };
    $stock = $get('stock');

    return [
        'sku'              => mb_substr($sku, 0, 64),
        'name'             => mb_substr($name, 0, 200),
        'brand'            => mb_substr($get('brand') ?? $keep('brand'), 0, 120),
        'weight'           => normalize_weight($get('weight') ?? $keep('weight')),
        'fiber'            => mb_substr($get('fiber') ?? $keep('fiber'), 0, 160),
        'colorway'         => mb_substr($get('colorway') ?? $keep('colorway'), 0, 120),
        'yardage'          => $int('yardage'),
        'grams'            => $int('grams'),
        'description'      => $get('description') ?? $keep('description'),
        'price_cents'      => $price,
        'sale_price_cents' => $sale,
        'stock'            => $stock !== null && $stock !== '' ? (int)$stock : (int)$keep('stock', 0),
        'is_active'        => parse_bool($get('is_active') ?? '', $existing ? (bool)$existing['is_active'] : true) ? 1 : 0,
        'is_featured'      => parse_bool($get('is_featured') ?? '', $existing ? (bool)$existing['is_featured'] : false) ? 1 : 0,
    ];
}

function unique_slug(string $base, ?int $ignoreId = null): string
{
    $slug = slugify($base);
    $try = $slug;
    $n = 2;
    $st = db()->prepare('SELECT id FROM products WHERE slug = ? AND id <> ?');
    while (true) {
        $st->execute([$try, $ignoreId ?? 0]);
        if (!$st->fetch()) {
            return $try;
        }
        $try = $slug . '-' . $n++;
    }
}

// Inserts or updates by id. Returns the product id.
function save_product(array $cols, ?int $id = null): int
{
    $pdo = db();
    $cols['slug'] = unique_slug($cols['name'] . ' ' . $cols['colorway'] . ' ' . $cols['brand'], $id);
    if (!array_key_exists('group_slug', $cols)) {
        // Yarn added in admin is its own group.
        $cols['group_slug'] = $cols['slug'];
    }
    if ($id) {
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($cols)));
        $pdo->prepare("UPDATE products SET $sets, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([...array_values($cols), $id]);
        return $id;
    }
    $names = implode(', ', array_keys($cols));
    $marks = implode(', ', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO products ($names) VALUES ($marks)")->execute(array_values($cols));
    return (int)$pdo->lastInsertId();
}

// True once schema_v9_hide.sql has been run.
function products_have_hide_columns(): bool
{
    static $have = null;
    if ($have === null) {
        try {
            db()->query('SELECT admin_hidden, meta_text FROM products WHERE 1 = 0');
            $have = true;
        } catch (PDOException $ex) {
            $have = false;
        }
    }
    return $have;
}

// Takes whole products (every colorway) out of the store, or puts them back.
// Hidden stays hidden through Shopify syncs. Putting back a Shopify product
// only makes it live again if the last sync still found it in Shopify.
function products_set_hidden(array $groupSlugs, bool $hide): int
{
    $groupSlugs = array_values(array_unique(array_filter(array_map('strval', $groupSlugs))));
    if (!$groupSlugs) {
        return 0;
    }
    $in = implode(', ', array_fill(0, count($groupSlugs), '?'));
    if ($hide) {
        $st = db()->prepare("UPDATE products SET admin_hidden = 1, is_active = 0 WHERE group_slug IN ($in)");
        $st->execute($groupSlugs);
        return $st->rowCount();
    }
    require_once __DIR__ . '/shopify_sync.php';
    $last = shopify_last_sync();
    $cut = $last && !empty($last['finished_at']) ? date('Y-m-d H:i:s', (int)$last['finished_at'] - (int)($last['seconds'] ?? 0) - 60) : '1970-01-01 00:00:00';
    $st = db()->prepare("UPDATE products SET admin_hidden = 0,
        is_active = CASE WHEN shopify_variant_id IS NULL OR synced_at IS NULL OR synced_at >= ? THEN 1 ELSE 0 END
        WHERE group_slug IN ($in)");
    $st->execute([$cut, ...$groupSlugs]);
    return $st->rowCount();
}

function product_by_sku(string $sku): ?array
{
    $st = db()->prepare('SELECT * FROM products WHERE sku = ?');
    $st->execute([$sku]);
    return $st->fetch() ?: null;
}

// Stores an uploaded image and returns its file name, or null when no file was sent.
function store_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('The image did not upload. Try a smaller file.');
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        throw new InvalidArgumentException('Images must be under 8 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        throw new InvalidArgumentException('Images must be JPG, PNG or WebP.');
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/../uploads/' . $name)) {
        throw new RuntimeException('Could not save the image. Check that uploads/ is writable.');
    }
    return $name;
}
