# Discount Yarn store

A small PHP + MySQL yarn shop for GoDaddy cPanel. Shoppers browse, fill a cart, and pay on Stripe Checkout (the Leafwood Advisory Stripe account). Stock drops automatically when Stripe confirms payment. Order emails go out through a dedicated Postmark server. Inventory is managed in a password-protected admin at `/admin/`.

## What's here

| Path | What it does |
| --- | --- |
| `index.php`, `shop.php`, `product.php` | Home, catalog (search, weight/brand filters, sale filter, sorting), product page |
| `cart.php`, `checkout.php`, `success.php` | Session cart, creates the Stripe Checkout Session, thank-you page |
| `webhook.php` | Stripe webhook: marks orders paid, takes stock, sends emails |
| `about.php`, `shipping.php`, `sitemap.php`, `robots.txt` | Info pages and SEO |
| `admin/` | Login, products (quick stock edits), add/edit product with photo, CSV import, orders, mark shipped |
| `includes/` | Config, DB, cart, Stripe and Postmark clients (no Composer needed) |
| `cron/expire_pending.php` | Daily cleanup of checkouts that never paid |
| `schema.sql` | Database tables, run once in phpMyAdmin |
| `config.sample.php` | Template for `config.php` (server only, never committed) |

## One-time setup

1. **Domain.** In cPanel, add `discount-yarn.com` as an addon domain with document root `public_html/discount-yarn.com` (the path `.cpanel.yml` deploys to). Turn on AutoSSL so it serves over https.
2. **Same cPanel account.** `.cpanel.yml` uses the same cPanel home folder as Leafwood. If the store is on a different hosting account, change the path in `DEPLOYPATH`.
3. **Database.** cPanel > MySQL Databases: create a database and a user, give the user all privileges on it. Open phpMyAdmin, select the database, and run `schema.sql`.
4. **Git deploy.** cPanel > Git Version Control: clone this repo, then Update from Remote and Deploy HEAD Commit (same flow as Leafwood).
5. **Config.** In cPanel File Manager, copy `config.sample.php` to `config.php` in the document root and fill it in:
   - `base_url` (`https://discount-yarn.com`), `support_email`, `owner_email`, store name and tagline. The sample already has discount-yarn.com addresses; change them if you use different mailboxes.
   - `db`: the database name, user and password from step 3.
   - `admin.password_hash`: run `php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), "\n";'` (cPanel Terminal works) and paste the result.
6. **Stripe (Leafwood account).**
   - Developers > API keys > Create restricted key. Give it **Write** on *Checkout Sessions* and nothing else. Put it in `stripe.secret_key`.
   - Developers > Webhooks > Add endpoint: `https://discount-yarn.com/webhook.php`, events `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`. Copy the signing secret into `stripe.webhook_secret`.
   - Every store payment is tagged with `metadata.store = discount-yarn` and described as "Discount Yarn order #...", so it's easy to tell apart from Leafwood client invoices in Stripe. The webhook ignores anything without that tag.
   - Optional: set `statement_descriptor_suffix` so card statements read e.g. `LEAFWOOD* DISCOUNT YARN`.
   - Optional: once Stripe Tax is set up, set `automatic_tax` to `true`.
7. **Postmark.** Create a new server for the store. Under Sender Signatures, verify the `discount-yarn.com` domain (add the DKIM and Return-Path DNS records in cPanel Zone Editor). Copy the server's API token into `postmark.server_token`.
8. **Cron.** cPanel > Cron Jobs, once a day: `php -q /home/xwy78w88pv1w/public_html/discount-yarn.com/cron/expire_pending.php`
9. **Test.** Use a Stripe test key and test webhook secret first, buy something with card `4242 4242 4242 4242`, and confirm the order shows in `/admin/orders.php` and the emails arrive. Then switch to live keys.

## Adding inventory

- One at a time: `/admin/` > Add product. Photos can be JPG, PNG or WebP up to 8 MB.
- In bulk: `/admin/import.php`. Download the template, fill it in, upload. Rows match on SKU, so the same file updates existing yarn. Blank cells keep current values. Choose "Add to current stock" when a new shipment arrives.
- Weights are Lace, Fingering, Sport, DK, Worsted, Aran, Bulky, Super Bulky.
- A sale price lower than the regular price shows as "X% off" and is what Stripe charges.

## How an order flows

1. Checkout re-reads prices and stock from the database (never from the browser), saves a `pending` order, and sends the shopper to Stripe.
2. When Stripe reports payment, `webhook.php` (or the thank-you page, if it lands first) marks the order `paid`, subtracts stock and emails the shopper and `owner_email`. Duplicate Stripe events are ignored.
3. If two people buy the last skein at the same moment, the order is flagged **oversold** in admin and in the owner email so you can refund the short item in Stripe.
4. In admin, open the order, add tracking, and click Mark as shipped to email the shopper.

## Security notes

- `config.php` and `uploads/*` are gitignored and excluded from deploy. Never commit them.
- `.htaccess` blocks `config.php`, `includes/`, `cron/` and `.sql` files from the web, and `uploads/.htaccess` stops scripts running from the photo folder.
- Card data never touches this server.
- Admin login is a single username and password from `config.php`, with CSRF protection and a short delay on each attempt.
