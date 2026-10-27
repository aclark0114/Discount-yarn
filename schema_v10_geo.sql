-- v10: coarse visitor location for admin > Visitors.
-- Run once in phpMyAdmin after schema_v9_hide.sql.
-- Only country, region and city are kept (looked up from the IP, which is never stored).

ALTER TABLE site_events ADD COLUMN country CHAR(2) NULL;
ALTER TABLE site_events ADD COLUMN region VARCHAR(80) NULL;
ALTER TABLE site_events ADD COLUMN city VARCHAR(80) NULL;
CREATE INDEX idx_events_country ON site_events (country, created_at);
