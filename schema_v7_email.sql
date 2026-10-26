-- v7: email list and blasts.
-- Run once in phpMyAdmin after schema_v6_categories.sql.

CREATE TABLE IF NOT EXISTS subscribers (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email            VARCHAR(255) NOT NULL,
  first_name       VARCHAR(100) NOT NULL DEFAULT '',
  status           VARCHAR(20)  NOT NULL DEFAULT 'pending',   -- pending, confirmed, unsubscribed
  token            CHAR(32)     NOT NULL,
  source           VARCHAR(40)  NOT NULL DEFAULT 'signup',    -- signup, invite
  created_at       DATETIME NOT NULL,
  confirm_sent_at  DATETIME NULL,
  confirmed_at     DATETIME NULL,
  unsubscribed_at  DATETIME NULL,
  UNIQUE KEY uq_subscribers_email (email),
  UNIQUE KEY uq_subscribers_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_blasts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subject     VARCHAR(200) NOT NULL,
  body        TEXT NOT NULL,
  created_at  DATETIME NOT NULL,
  sent_at     DATETIME NULL,
  recipients  INT UNSIGNED NOT NULL DEFAULT 0,
  failed      INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sign-up throttle. Holds a daily-changing hash, never an IP address.
CREATE TABLE IF NOT EXISTS signup_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  who         CHAR(16) NOT NULL,
  created_at  DATETIME NOT NULL,
  KEY idx_signup_who (who, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
