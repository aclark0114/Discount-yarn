# Discount Yarn store

PHP 8 + MySQL yarn shop on GoDaddy cPanel. Stripe Checkout (Leafwood Advisory's Stripe account) for payments, a dedicated Postmark server for email. The catalog is mirrored from the Stars Hollow Shopify store (read-only) into the products table; Shopify is never part of checkout. See README.md for setup.

## Deploy
- Push to GitHub, then in cPanel: Update from Remote, Deploy HEAD Commit. `.cpanel.yml` rsyncs into the document root and never deletes.
- Never push to main. Work on a branch and tell the owner what changed.
- Keep the `.cpanel.yml` excludes (`config.php`, `uploads/*`, `.sql`, zips, backups, `error_log`).

## Secrets (never commit or print)
- The config (`/home/<user>/discount-yarn-config.php`, or `config.php` in the document root) holds the Shopify client secret, DB password, Stripe key, webhook secret, Postmark token and admin hash. Only `config.sample.php` (placeholders) is in the repo.
- `uploads/` holds product photos on the server only. The cache folder (`cache_dir`, outside public_html) holds Shopify tokens; never print or log them.

## Code conventions
- No Composer. Stripe and Postmark are called with curl in `includes/stripe.php` and `includes/postmark.php`.
- Every page starts with `require __DIR__ . '/includes/bootstrap.php'`. Escape output with `e()`, money with `money()` (cents everywhere).
- Every POST form includes `csrf_field()` and its handler calls `csrf_check()`.
- Prices and stock are read from the database at checkout, never trusted from the browser. Shopify-synced rows (`shopify_variant_id` set) are read-only in admin; checkout re-checks them live with `shopify_revalidate_lines()`.
- Store pages never call Shopify; only cron, admin and checkout do.
- `shopify_storefront_only()` (storefront_token set) means no Admin API calls at all: sync and live checks go through the Storefront API and stock push is skipped. Keep both paths working.
- Checkout Sessions carry `metadata.store = discount-yarn`; the webhook ignores sessions without it because the Stripe account is shared with Leafwood.
- Schema changes go in a new `schema_vN_*.sql` file run by hand in phpMyAdmin. SQL in pages must also run on SQLite (used for local testing).
- Cron scripts run under `php -q` and exit if called over the web (see `cron/expire_pending.php`).
- Bump `ASSET_VERSION` in `includes/layout.php` when `assets/styles.css` or `assets/script.js` changes.

## Design and copy
- Colors are CSS variables on `:root` in `assets/styles.css` (cream, paper, evergreen, berry, mustard, oat). Fonts: Lora (headings), Nunito Sans (body). Radius `--r: 6px`. Respect `prefers-reduced-motion`.
- Copy: no em-dashes.

## Before committing
- `php -l` every changed PHP file.
- Preview changed pages at desktop and phone width.
