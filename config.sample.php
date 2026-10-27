<?php
// Copy this file to the server and fill in real values. Preferred location
// is outside the web root: /home/<cpaneluser>/discount-yarn-config.php
// (config.php in the document root still works as a fallback).
// Neither copy is committed or deployed. Never commit real values.

return [
    // Shopify (Stars Hollow store).
    // Admin API: OAuth authorization code grant. Open /shopify/install.php once
    // to connect; the token is saved as shopify_token.json next to this file.
    'shopify' => [
        'shop_domain'      => 'REPLACE.myshopify.com', // the *.myshopify.com handle, NOT starshollowyarns.com
        'api_version'      => '2026-10',
        'client_id'        => '',                      // Dev Dashboard > app > Settings
        'client_secret'    => '',                      // Dev Dashboard > app > Settings
        'scopes'           => 'read_products,read_inventory', // add ,write_inventory to lower Shopify stock after sales
        'redirect_uri'     => 'https://discount-yarn.com/shopify/callback.php', // must match the app exactly
        // Storefront API: the Headless sales channel token.
        'storefront_token' => '',
        'storefront_token_type' => 'auto',             // auto, public or private (private tokens start shpss_)
        // Optional: the Shopify location to take sold stock from, e.g.
        // gid://shopify/Location/123. Blank picks the location with the most stock.
        'location_id'      => '',
    ],

    // Discount tiers offered in admin > Pricing (percent off Shopify's price).
    'discount_buckets' => [10, 25, 50],

    // Tokens and the catalog cache. Must be writable and outside public_html.
    'cache_dir' => __DIR__ . '/discount-yarn-cache',

    // Optional: where cron/geo_update.php keeps the DB-IP city database used for
    // visitor locations. Leave unset to use dbip-city-lite.mmdb inside cache_dir.
    // 'geoip_db' => __DIR__ . '/discount-yarn-cache/dbip-city-lite.mmdb',

    // Store identity
    'store_name'    => 'Discount Yarn',
    'tagline'       => 'Good yarn. Better prices.',
    'base_url'      => 'https://discount-yarn.com',  // no trailing slash
    'timezone'      => 'America/New_York',           // times in admin, emails and visitor stats
    'support_email' => 'hello@discount-yarn.com',         // shown to shoppers, reply-to on emails
    'owner_email'   => 'orders@discount-yarn.com',        // gets a copy of every new order
    // Postal address printed in every email blast (required by US law, CAN-SPAM).
    'mailing_address' => '',

    // Under construction: normally switched in admin > Store status. Set to
    // true here only if admin can't be reached.
    // 'maintenance' => true,

    // MySQL (create the database and user in cPanel > MySQL Databases)
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=CHANGE_ME;charset=utf8mb4',
        'user' => 'CHANGE_ME',
        'pass' => 'CHANGE_ME',
    ],

    // Stripe (Leafwood Advisory account). Use a restricted key with write
    // access to Checkout Sessions only. The webhook secret comes from the
    // endpoint you add in Stripe for https://discount-yarn.com/webhook.php
    'stripe' => [
        'secret_key'     => 'rk_live_CHANGE_ME',
        // Publishable key (pk_live_... or pk_test_...) for the payment form on
        // our checkout page. Blank sends shoppers to Stripe's hosted page instead.
        'publishable_key' => 'pk_live_CHANGE_ME',
        'webhook_secret' => 'whsec_CHANGE_ME',
        // Shown on the shopper's card statement (max 22 chars, letters/numbers/spaces).
        'statement_descriptor_suffix' => 'DISCOUNT YARN',
        // Turn on only after Stripe Tax is set up on the account.
        'automatic_tax'  => false,
    ],

    // Postmark: the new server for this store. Server API token, not the account token.
    'postmark' => [
        'server_token'   => 'CHANGE_ME',
        'from'           => 'Discount Yarn <orders@discount-yarn.com>',  // must be a verified sender signature or domain
        'message_stream' => 'outbound',          // order and sign-up confirmation emails
        'broadcast_stream' => 'broadcast',       // email blasts (create a Broadcast stream in Postmark)
    ],

    // Shipping (US only to start). Amounts in cents.
    'shipping' => [
        'countries'        => ['US'],
        'flat_rate_cents'  => 695,
        'free_over_cents'  => 7500,
    ],

    // Admin login. After the first login you can change the password in
    // admin > Change password; it's then kept in discount-yarn-admin.json next
    // to this file, and deleting that file brings back this one. Generate a hash with:
    //   php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), "\n";'
    'admin' => [
        'username'      => 'admin',
        'password_hash' => 'CHANGE_ME',
    ],
];
