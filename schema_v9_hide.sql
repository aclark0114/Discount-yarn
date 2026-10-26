-- v9: products hidden by the owner (kept hidden through Shopify syncs), and
-- Shopify's tags, SEO title and description and photo alt text, so admin can
-- search them. Run once in phpMyAdmin after schema_v8_consent.sql.

ALTER TABLE products ADD COLUMN admin_hidden TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN meta_text TEXT NULL;
