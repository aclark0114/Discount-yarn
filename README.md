# Discount Yarn store

A small PHP + MySQL yarn shop for GoDaddy cPanel. Shoppers browse, fill a cart, and pay on Stripe Checkout (the Leafwood Advisory Stripe account). Stock drops automatically when Stripe confirms payment. Order emails go out through a dedicated Postmark server. Products, photos, prices and stock are copied from the Stars Hollow Shopify store every 15 minutes; Shopify is never involved in checkout. A password-protected admin lives at `/admin/`.

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
| `cron/shopify_sync.php`, `includes/shopify_sync.php` | Copies the Shopify catalog into the products table (every 15 minutes) |
| `includes/shopify.php`, `cron/shopify_check.php`, `admin/shopify.php` | Shopify login, token caching, connection test, Sync now |
| `schema.sql`, `schema_v2_shopify.sql`, `schema_v3_pricing_groups.sql`, `schema_v4_images.sql` | Database tables, run once each in phpMyAdmin, in order |
| `admin/pricing.php`, `includes/pricing.php` | Discount tiers for Shopify yarn |
| `includes/catalog.php` | Grouped catalog: one card and one page per product, with a colorway picker |
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

## Shopify connection (Stars Hollow inventory)

Shopify supplies the catalog only: products, photos, prices and stock. Shoppers still pay on Stripe Checkout here, so the Stars Hollow store never sees the checkout.

- **Where config lives.** Put the config at `/home/xwy78w88pv1w/discount-yarn-config.php`, outside `public_html`. The store loads it first and falls back to `config.php` in the document root. Copy the `shopify` and `cache_dir` blocks from `config.sample.php`.
- **Shopify settings.**
  - `shop_domain`: the store's `something.myshopify.com` address (Shopify admin > Settings > Domains), not starshollowyarns.com.
  - `client_id` and `client_secret`: from Dev Dashboard > the app > Settings.
  - `storefront_token`: can stay blank. The store creates one through the Admin API the first time it's needed and caches it.
- **Cache folder.** `cache_dir` defaults to `/home/xwy78w88pv1w/discount-yarn-cache`, next to the config file. It holds the Admin token (renewed automatically about 5 minutes before its 24 hour expiry) and the Storefront token. It must be writable and outside `public_html`.
- **Test it.** Open `/admin/shopify.php` and click Run connection test, or in cPanel Terminal run `php -q /home/xwy78w88pv1w/public_html/discount-yarn.com/cron/shopify_check.php`. Either one shows the shop name, product count, granted scopes and a Storefront API check. Tokens are never shown.
- **Database.** Run `schema_v2_shopify.sql`, then `schema_v3_pricing_groups.sql`, then `schema_v4_images.sql`, in phpMyAdmin once each, after `schema.sql`. Run them **before** deploying the code that needs them.
- **App scopes.** The Dev Dashboard app needs `read_products`, `read_inventory` and `write_inventory`. After adding a scope, release a new app version and approve it on the store.
- **Sync.** Add a cron job every 15 minutes: `php -q /home/xwy78w88pv1w/public_html/discount-yarn.com/cron/shopify_sync.php`. To refresh right away, use Sync catalog now on `/admin/shopify.php`. Pages always read the local copy, so the site stays up if Shopify is slow or down.
- **How it maps.**
  - Each Shopify product gets one card in the shop and one page, with a picker for its colorways (variants). Sold-out colorways are shown but can't be picked. Vendor becomes brand.
  - **Photos.** All photos come from Shopify and load from Shopify's image servers (resized with `?width=`), so nothing is copied onto this server. Each colorway uses its own photo, falling back to the product's main photo. The product page also shows up to 10 product photos as thumbnails, in Shopify's order and with Shopify's alt text. Videos and 3D models are skipped. Photo changes in Shopify appear after the next sync.
  - Weight is guessed from the product type, tags or a "Weight" option, and fiber from a tag such as `100% merino` or `fiber: alpaca`.
  - Only active Shopify products are copied. Products that are deleted, archived or set to draft disappear from the store on the next sync.
- **Prices (discount tiers).** In admin > Pricing, choose a tier for the whole store, for each brand, and for any single product. A product's own tier beats its brand's, and a brand's beats the store default. Shoppers pay Shopify's price less the tier. The struck-through price is the higher of Shopify's price and its compare-at price. Changes apply immediately. The tiers on offer come from `discount_buckets` in the config (default 10, 25 and 50 percent; "No discount" is always available).
- **Stock.** When Stripe confirms a payment, the store subtracts the skeins from Shopify, so Stars Hollow can't sell them again.
  - **Which location:** the one in `shopify.location_id`, or the location with the most stock if that's blank.
  - **Duplicates:** each order is sent with an idempotency key and only once.
  - **If Shopify refuses or can't be reached:** the order page shows why, with a Retry now button, and the sync cron retries every 15 minutes. Until it succeeds, those skeins are held back on this site.
  - **Before Stripe:** right before sending a shopper to Stripe, checkout asks Shopify for the live count and price. If either changed, the shopper goes back to the cart to review it. If Shopify can't be reached, checkout uses the last sync.
- **Editing.** Synced yarn can't be edited in admin or by CSV import, because the next sync would overwrite it. Change it in Shopify instead. Featuring a yarn on the home page still works here.
- **`shop_not_permitted`.** This error means the Dev Dashboard app belongs to a different Shopify organization than the store. Create the app in the organization that owns the store, install it, and update the client ID and secret. After a failed token request, the store waits 5 minutes before trying again on page loads. The test page always tries again straight away.

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
