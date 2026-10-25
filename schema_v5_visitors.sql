-- v5: first-party visitor activity for admin > Visitors.
-- Run once in phpMyAdmin after schema_v4_images.sql.
-- No IP addresses are stored: visitors are a daily-changing anonymous hash.

CREATE TABLE IF NOT EXISTS site_events (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at     DATETIME NOT NULL,
  visitor        CHAR(16) NOT NULL,
  event          VARCHAR(20) NOT NULL,          -- view, add_to_cart, checkout
  path           VARCHAR(200) NOT NULL DEFAULT '',
  product        VARCHAR(160) NULL,             -- product page slug (group_slug)
  value_cents    INT UNSIGNED NULL,             -- cart value for add_to_cart and checkout
  referrer_host  VARCHAR(120) NULL,
  utm_source     VARCHAR(100) NULL,
  utm_medium     VARCHAR(100) NULL,
  utm_campaign   VARCHAR(100) NULL,
  device         VARCHAR(10) NOT NULL DEFAULT 'desktop',
  KEY idx_events_time (created_at),
  KEY idx_events_type (event, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
