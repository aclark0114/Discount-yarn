-- v8: record how people added by hand agreed to be emailed.
-- Run once in phpMyAdmin after schema_v7_email.sql.

ALTER TABLE subscribers ADD COLUMN consent_note VARCHAR(255) NULL;
