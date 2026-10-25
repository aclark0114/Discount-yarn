-- v3: discount tiers, grouped product pages and pushing sold stock to Shopify.
-- Run once in phpMyAdmin after schema_v2_shopify.sql.

-- Shopify's own prices, kept so tier changes apply without a re-sync.
ALTER TABLE products ADD COLUMN shopify_price_cents INT NULL;
ALTER TABLE products ADD COLUMN shopify_compare_cents INT NULL;
ALTER TABLE products ADD COLUMN shopify_inventory_item_id VARCHAR(64) NULL;
-- One product page per group: the Shopify handle, or the yarn's own slug.
ALTER TABLE products ADD COLUMN group_slug VARCHAR(160) NULL;
CREATE INDEX idx_products_group ON products (group_slug);
UPDATE products SET group_slug = slug WHERE group_slug IS NULL;

-- Sold skeins are subtracted from Shopify once per order.
ALTER TABLE orders ADD COLUMN inventory_pushed_at DATETIME NULL;
ALTER TABLE orders ADD COLUMN inventory_push_error TEXT NULL;
-- Shipped orders were already taken out of Shopify by hand; paid ones get pushed.
UPDATE orders SET inventory_pushed_at = CURRENT_TIMESTAMP WHERE status = 'shipped';

-- Discount tiers: scope is default, brand or product (Shopify product id).
CREATE TABLE IF NOT EXISTS price_rules (
  scope        VARCHAR(20)  NOT NULL,
  match_value  VARCHAR(255) NOT NULL DEFAULT '',
  percent      INT NOT NULL,
  PRIMARY KEY (scope, match_value)
);
