-- Discount Yarn store schema (MySQL / MariaDB).
-- Run once in phpMyAdmin on the store's database. Later changes go in
-- schema_v2_*.sql etc. so they can be run by hand in order.

CREATE TABLE IF NOT EXISTS products (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sku               VARCHAR(64)  NOT NULL,
  slug              VARCHAR(160) NOT NULL,
  name              VARCHAR(200) NOT NULL,
  brand             VARCHAR(120) NOT NULL DEFAULT '',
  weight            VARCHAR(40)  NOT NULL DEFAULT '',
  fiber             VARCHAR(160) NOT NULL DEFAULT '',
  colorway          VARCHAR(120) NOT NULL DEFAULT '',
  yardage           INT UNSIGNED NULL,
  grams             INT UNSIGNED NULL,
  description       TEXT NULL,
  price_cents       INT UNSIGNED NOT NULL,
  sale_price_cents  INT UNSIGNED NULL,
  stock             INT NOT NULL DEFAULT 0,
  image             VARCHAR(200) NULL,
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  is_featured       TINYINT(1) NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_sku (sku),
  UNIQUE KEY uq_products_slug (slug),
  KEY idx_products_active (is_active, weight)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  public_id              CHAR(16) NOT NULL,
  status                 VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending, paid, shipped, cancelled, expired
  stripe_session_id      VARCHAR(255) NULL,
  stripe_payment_intent  VARCHAR(255) NULL,
  email                  VARCHAR(255) NOT NULL DEFAULT '',
  name                   VARCHAR(200) NOT NULL DEFAULT '',
  shipping_address       TEXT NULL,
  subtotal_cents         INT UNSIGNED NOT NULL DEFAULT 0,
  shipping_cents         INT UNSIGNED NOT NULL DEFAULT 0,
  tax_cents              INT UNSIGNED NOT NULL DEFAULT 0,
  total_cents            INT UNSIGNED NOT NULL DEFAULT 0,
  oversold               TINYINT(1) NOT NULL DEFAULT 0,
  tracking_number        VARCHAR(120) NULL,
  admin_notes            TEXT NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at                DATETIME NULL,
  shipped_at             DATETIME NULL,
  UNIQUE KEY uq_orders_public (public_id),
  UNIQUE KEY uq_orders_session (stripe_session_id),
  KEY idx_orders_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id          INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NULL,
  sku               VARCHAR(64)  NOT NULL,
  name              VARCHAR(255) NOT NULL,
  unit_price_cents  INT UNSIGNED NOT NULL,
  quantity          INT UNSIGNED NOT NULL,
  KEY idx_items_order (order_id),
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stripe can deliver the same event more than once; this keeps handling idempotent.
CREATE TABLE IF NOT EXISTS webhook_events (
  event_id     VARCHAR(255) NOT NULL PRIMARY KEY,
  type         VARCHAR(120) NOT NULL,
  received_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
