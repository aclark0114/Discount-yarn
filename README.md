# Discount Yarn store

A small PHP + MySQL yarn shop for GoDaddy cPanel. Shoppers browse, fill a cart, and pay in Stripe's Embedded Checkout form on the site's own checkout page (the Leafwood Advisory Stripe account). Stock drops automatically when Stripe confirms payment. Order emails go out through a dedicated Postmark server. Products, photos, prices and stock are copied from the Stars Hollow Shopify store every 15 minutes; Shopify is never involved in checkout. A password-protected admin lives at `/admin/`.

## What's here

| Path | What it does |
| --- | --- |
| `index.php`, `shop.php`, `product.php` | Home, catalog (search, weight/brand filters, sale filter, sorting), product page |
| `cart.php`, `checkout.php`, `success.php` | Session cart, checkout page with Stripe's embedded payment form, thank-you page |
| `webhook.php` | Stripe webhook: marks orders paid, takes stock, sends emails |
| `about.php`, `shipping.php`, `sitemap.php`, `robots.txt` | Info pages and SEO |
| `admin/` | Login, products (quick stock edits), add/edit product with photo, CSV import, orders, mark shipped |
| `includes/` | Config, DB, cart, Stripe and Postmark clients (no Composer needed) |
| `cron/expire_pending.php` | Daily cleanup of checkouts that never paid, and of visitor data older than 180 days |
| `track.php`, `includes/activity.php`, `admin/visitors.php` | First-party visitor activity and the admin Visitors page |
| `cron/shopify_sync.php`, `includes/shopify_sync.php` | Copies the Shopify catalog into the products table (every 15 minutes) |
| `includes/shopify.php`, `cron/shopify_check.php`, `admin/shopify.php` | Shopify login, token caching, connection test, Sync now |
| `schema.sql`, `schema_v2_shopify.sql`, `schema_v3_pricing_groups.sql`, `schema_v4_images.sql`, `schema_v5_visitors.sql`, `schema_v6_categories.sql`, `schema_v7_email.sql`, `schema_v8_consent.sql` | Database tables, run once each in phpMyAdmin, in order |
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
   - **Check it:** `/admin/stripe.php` reports whether each key looks right: the right kind, not the masked dashboard copy, both keys in the same mode. It also checks that Stripe accepts the secret key. Only each key's type, mode and last 4 characters are shown. Stray spaces, line breaks or quotes pasted around a key are ignored.
   - Copy the **publishable key** (`pk_live_...`, same page) into `stripe.publishable_key`. With it, the payment form appears on discount-yarn.com/checkout.php (Stripe Embedded Checkout). Without it, shoppers are sent to Stripe's own hosted page instead.
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
  - `storefront_token`: the Headless sales channel's public token (Shopify admin > Sales channels > Headless > your storefront). Used for every Storefront API call. A private `shpss_` token also works; `storefront_token_type` picks the header if the guess is wrong.
  - `scopes` (default `read_products,read_inventory`) and `redirect_uri` (default `https://discount-yarn.com/shopify/callback.php`, which must match the app exactly).
- **Connect the Admin API (OAuth).** The app lives in a different Shopify organization from the store, so the store connects with the authorization code grant:
  1. Open `https://discount-yarn.com/shopify/install.php`. It sends you to Shopify for the store in `shop_domain`, and no other.
  2. A Stars Hollow staff member with permission to install apps approves it.
  3. Shopify returns to `/shopify/callback.php`. That page checks the state and the hmac, trades the code for an offline token, and shows "Connected to …".
  - **Where the token goes:** `shopify_token.json`, next to the config file, with permissions 600. That folder must be outside `public_html`, or connecting is refused.
  - **Reconnecting:** a `shopify_connected.lock` file is written beside the token. While it exists, both pages refuse to run until the admin password is entered on the install page. To start over completely, delete the lock and token files.
  - **Before connecting:** every admin page shows a "Connect to Shopify" message, and the catalog syncs through the Storefront token only.
- **Cache folder.** `cache_dir` defaults to `/home/xwy78w88pv1w/discount-yarn-cache`, next to the config file. It holds the sync status and lock files. It must be writable and outside `public_html`.
- **Test it.** Open `/admin/shopify.php` and click Run connection test, or in cPanel Terminal run `php -q /home/xwy78w88pv1w/public_html/discount-yarn.com/cron/shopify_check.php`. It shows the shop name, the product count from the Storefront API, the Admin connection and scopes, and stock for three products from the Admin API. Tokens are never shown.
- **Database.** Run `schema_v2_shopify.sql`, then `schema_v3_pricing_groups.sql`, then `schema_v4_images.sql`, in phpMyAdmin once each, after `schema.sql`. Run them **before** deploying the code that needs them.
- **App scopes.** The connection asks for `scopes` from the config, `read_products,read_inventory` by default. To have sales lower Shopify stock automatically, add `write_inventory` to the app and to `scopes`, then reconnect. Without it, lower stock in Shopify by hand when you ship. Until an order is marked shipped, its skeins are held back on this site.
- **Sync.** Add a cron job every 15 minutes: `php -q /home/xwy78w88pv1w/public_html/discount-yarn.com/cron/shopify_sync.php`. To refresh right away, use Sync catalog now on `/admin/shopify.php`. Pages always read the local copy, so the site stays up if Shopify is slow or down.
- **How it maps.**
  - Each Shopify product gets one card in the shop and one page, with a picker for its colorways (variants). Sold-out colorways are shown but can't be picked. Vendor becomes brand.
  - **Photos.** All photos come from Shopify and load from Shopify's image servers (resized with `?width=`), so nothing is copied onto this server. Each colorway uses its own photo, falling back to the product's main photo. The product page also shows up to 10 product photos as thumbnails, in Shopify's order and with Shopify's alt text. Videos and 3D models are skipped. Photo changes in Shopify appear after the next sync.
  - Weight is guessed from the product type, tags or a "Weight" option, and fiber from a tag such as `100% merino` or `fiber: alpaca`.
  - **Yarn or accessory.** Shopify product types such as Book, Bag, Notions, Needles, Kit or Pattern are accessories, even if a tag mentions a weight (a pattern book tagged "worsted", for example). Otherwise a product is yarn if a weight is found, or if its type or tags say yarn. Accessories appear under Accessories in the menu, never on weight pages or in the home page photos.
  - **Menu.** The row of weights under the header lists every weight that has yarn in the catalog, from Lace to Super Bulky.
  - Only active Shopify products are copied. Products that are deleted, archived or set to draft disappear from the store on the next sync.
- **Prices (discount tiers).** In admin > Pricing, choose a tier for the whole store, for each brand, and for any single product. A product's own tier beats its brand's, and a brand's beats the store default. Shoppers pay Shopify's price less the tier. The struck-through price is the higher of Shopify's price and its compare-at price. Changes apply immediately. The tiers on offer come from `discount_buckets` in the config (default 10, 25 and 50 percent; "No discount" is always available).
- **Stock.** With `write_inventory` granted, the store subtracts the skeins from Shopify when Stripe confirms a payment, so Stars Hollow can't sell them again.
  - **Which location:** the one in `shopify.location_id`, or the location with the most stock if that's blank.
  - **Duplicates:** each order is sent with an idempotency key and only once.
  - **If Shopify refuses or can't be reached:** the order page shows why, with a Retry now button, and the sync cron retries every 15 minutes. Until it succeeds, those skeins are held back on this site.
  - **Before Stripe:** right before sending a shopper to Stripe, checkout asks Shopify for the live count and price. If either changed, the shopper goes back to the cart to review it. If Shopify can't be reached, checkout uses the last sync.
- **Editing.** Synced yarn can't be edited in admin or by CSV import, because the next sync would overwrite it. Change it in Shopify instead. Featuring a yarn on the home page still works here.
- **Before the Admin API is connected.** The catalog sync and the live stock check use the Storefront token alone.
  - **Storefront permissions:** in the Headless channel, turn on product listings and **Read product inventory** (`unauthenticated_read_product_inventory`), and make the products available to that channel.
  - **Once connected:** the sync switches to the Admin API.

## Email list and blasts

- **Signing up:** shoppers sign up in the footer of every page or at `/subscribe.php`. They're only added once they click the link in a confirmation email (double opt-in).
  - **Spam protection:** a hidden trap field and a limit of 5 sign-ups an hour per visitor.
  - **Privacy:** the form gives the same answer whether or not an address is already on the list, so it can't be used to check who's subscribed.
- **Admin > Email** (`/admin/email.php`):
  - **Writing:** plain text, with a blank line between paragraphs. Web addresses become links and `{first_name}` fills in each person's name.
  - **Product cards:** a line with just `[product:slug]` (the part after `slug=` in a product link) becomes a card with that product's photo, current price including any discount tier, and a Shop now button, filled in at send time.
  - **Bright blocks:** `[banner:Last call]` is a bold headline strip. `[lastchance]` is a grid of four yarns showing how many skeins are left, with colorway photos that each open that colorway. On its own it picks the four in-stock yarns with the fewest skeins left, checked at send time; `[lastchance:slug, slug, slug, slug]` picks them yourself.
  - **Tracking:** every link to the store in a blast gets `utm_source=newsletter&utm_medium=email&utm_campaign=<date>-<subject>`, and the sign-up confirmation email uses `utm_source=list-confirm`. Tests and previews use `utm_source=test-email` so they don't count. Admin > Visitors > Campaigns shows visitors, adds to cart and checkouts for each (same day, since the visitor id changes daily). Links you write that already have `utm_` tags are left as they are.
  - **Tests:** a test goes to any one address (your order email by default), isn't added to the list, and has a placeholder unsubscribe link.
  - **Images:** upload an image on the same page, then put the `[image:https://...|description]` line it gives you on its own line. The description shows in the plain-text version and to screen readers.
  - **Preview and checks:** Preview shows the email before you test or send. A misspelled product or a non-https image stops the test or send with a message.
  - **Sending:** send yourself a test, then tick the confirm box and send to everyone confirmed. Blasts go through Postmark's **broadcast** stream in batches of 500. Past blasts are listed with delivered and failed counts.
  - **Invites:** each invited person gets one confirmation email and only receives blasts if they click it. Only invite people who'd expect to hear from you.
  - **Add as confirmed:** adds people with no confirmation email, for people who already agreed to your email (signed up in person, by phone, on a paper form). You must tick the permission box and say how they agreed; that note is saved with each person and shows when you hover "Added by hand". People who unsubscribed are never added back.
- **Every blast includes** the store name, the `mailing_address` from the config (required by US law; sending is blocked until it's set) and an unsubscribe link. It also carries the one-click unsubscribe headers that Gmail and Yahoo require.
- **Unsubscribing:** the unsubscribe page asks for a click, so email security scanners that open links can't unsubscribe people by accident. Mail apps' one-click unsubscribe works directly.
- **Setup:**
  1. Run `schema_v7_email.sql`, then `schema_v8_consent.sql`, in phpMyAdmin.
  2. In Postmark, add a **Broadcast** message stream to the store's server, named `broadcast` or whatever is in `postmark.broadcast_stream`.
  3. Set `mailing_address` in the config.

## Admin password

Admin > **Change password** asks for the current password and a new one (at least 12 characters, typed twice). The new password is saved as a hash in `discount-yarn-admin.json` next to the config, outside public_html, and from then on it's the one that works; the config's `admin.password_hash` is ignored while that file exists. Changing it logs out every other browser signed into admin. It's also the password for reconnecting Shopify.

**Forgot it?** In cPanel File Manager, delete `discount-yarn-admin.json` from your home folder (next to `discount-yarn-config.php`). The password from the config works again.

## Under construction

Admin > **Store status** closes the store to shoppers while you make changes, and opens it again. No deploy is needed either way.
- **Shoppers** see a "We'll be back soon" page with your message (optional), an email sign-up box and the support email. It answers with a 503 and Retry-After, which search engines treat as temporary.
- **You**, while logged into admin, see the real store with a red reminder bar, so you can check changes. To see what shoppers see, use a private window.
- **Still working:** the Stripe webhook, the order success page, sign-up, confirm and unsubscribe links, the Shopify connection and cron jobs.
- The switch is a file, `discount-yarn-maintenance.json`, saved next to the config (outside public_html), so deploys don't change it. If admin can't be reached, `'maintenance' => true` in the config closes the store too.

## Logo and favicon

The header shows `assets/logo.png` and the browser tab shows `assets/favicon-32.png` and `assets/apple-touch-icon.png` when those files exist. If they're missing, the store name is shown as text and the old SVG favicon is used. To change them, replace the files in the repo, or upload them to the same paths in cPanel File Manager:
- `logo.png`: about 88px tall, transparent background.
- `favicon-32.png`: 32 by 32.
- `apple-touch-icon.png`: 180 by 180.

## Visitor activity

`/admin/visitors.php` shows, for today or the last 7, 30 or 90 days:
- **Summary:** who is on the site now, visitors, page views, and orders with revenue.
- **Shopper funnel:** visited, viewed a product, added to cart, started checkout, ordered.
- **Breakdowns:** visitors per day, most viewed products with add-to-cart counts, top pages, where visitors came from (referring site and UTM campaign tags), and devices.
- **Recent activity:** a list of the latest events.

How it works:
- **Recording:** page views come from a small first-party ping in `assets/script.js` to `track.php`. Add-to-cart and checkout are recorded by the server.
- **Who's left out:** bots, browsers with Do Not Track on, and logged-in admins.
- **Privacy:** no IP addresses or tracking cookies are stored. A visitor is an anonymous ID that changes daily, so "visitors" means people per day. Data older than 180 days is deleted by the daily cron.
- **Setup:** run `schema_v5_visitors.sql` in phpMyAdmin first. Until then, the page says so.
- **Time zone:** times use `timezone` from the config (default `America/New_York`).

## Adding inventory

- One at a time: `/admin/` > Add product. Photos can be JPG, PNG or WebP up to 8 MB.
- In bulk: `/admin/import.php`. Download the template, fill it in, upload. Rows match on SKU, so the same file updates existing yarn. Blank cells keep current values. Choose "Add to current stock" when a new shipment arrives.
- Weights are Lace, Fingering, Sport, DK, Worsted, Aran, Bulky, Super Bulky.
- A sale price lower than the regular price shows as "X% off" and is what Stripe charges.

## How an order flows

1. Checkout re-reads prices and stock from the database (never from the browser), saves a `pending` order, and shows Stripe's payment form on `/checkout.php`. If the cart changes before paying, the shopper is sent back to the cart and a fresh checkout starts.
   - **If checkout can't start:** shoppers see a generic message. A logged-in admin sees Stripe's exact error in the same message, and it's also written to the server's error_log.
2. When Stripe reports payment, `webhook.php` (or the thank-you page, if it lands first) marks the order `paid`, subtracts stock and emails the shopper and `owner_email`. Duplicate Stripe events are ignored.
3. If two people buy the last skein at the same moment, the order is flagged **oversold** in admin and in the owner email so you can refund the short item in Stripe.
4. In admin, open the order, add tracking, and click Mark as shipped to email the shopper.

## Security notes

- `config.php` and `uploads/*` are gitignored and excluded from deploy. Never commit them.
- `.htaccess` blocks `config.php`, `includes/`, `cron/` and `.sql` files from the web, and `uploads/.htaccess` stops scripts running from the photo folder.
- Card data never touches this server.
- Admin login is a single username and password from `config.php`, with CSRF protection and a short delay on each attempt.
