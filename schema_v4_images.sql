-- v4: every photo of a Shopify product, for the product page gallery.
-- Run once in phpMyAdmin after schema_v3_pricing_groups.sql.

CREATE TABLE IF NOT EXISTS product_images (
  shopify_product_id  VARCHAR(64)  NOT NULL,
  position            INT NOT NULL,
  url                 VARCHAR(500) NOT NULL,
  alt_text            VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (shopify_product_id, position)
);
