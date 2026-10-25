-- v2: products mirrored from the Stars Hollow Shopify store.
-- Run once in phpMyAdmin after schema.sql. One row per Shopify variant.

ALTER TABLE products ADD COLUMN shopify_product_id VARCHAR(64) NULL;
ALTER TABLE products ADD COLUMN shopify_variant_id VARCHAR(64) NULL;
ALTER TABLE products ADD COLUMN image_url VARCHAR(500) NULL;
ALTER TABLE products ADD COLUMN synced_at DATETIME NULL;
CREATE UNIQUE INDEX uq_products_variant ON products (shopify_variant_id);
