-- v6: yarn vs accessories (bags, books, needles, notions).
-- Run once in phpMyAdmin after schema_v5_visitors.sql. The next catalog
-- sync fills these in properly from Shopify's product type and tags.

ALTER TABLE products ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'yarn';
ALTER TABLE products ADD COLUMN product_type VARCHAR(120) NOT NULL DEFAULT '';
UPDATE products SET category = 'accessory' WHERE weight = '';
