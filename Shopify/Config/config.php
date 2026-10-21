<?php

return [
    'name' => 'Shopify',

    // my-store.myshopify.com
    'shop_domain' => env('SHOPIFY_SHOP_DOMAIN', ''),

    // Apps created in the Shopify Dev Dashboard: Client ID + Client secret.
    'client_id'     => env('SHOPIFY_CLIENT_ID', ''),
    'client_secret' => env('SHOPIFY_CLIENT_SECRET', ''),

    // Optional: Admin API access token ("shpat_...") of a legacy custom app created
    // before 2026-01-01. Leave empty when using the Client ID / Client secret.
    'access_token' => env('SHOPIFY_ACCESS_TOKEN', ''),

    // Shopify Admin GraphQL API version (a new one is released every quarter).
    'api_version' => env('SHOPIFY_API_VERSION', '2026-10'),

    // Fill empty FreeScout customer fields with the Shopify customer data.
    'sync_customers' => env('SHOPIFY_SYNC_CUSTOMERS', true),

    // Turn order numbers found in messages into links to the order.
    'link_orders' => env('SHOPIFY_LINK_ORDERS', true),

    // Only let agents open the orders that belong to the customer of the conversation
    // (matched by email address). By default any order of the store can be looked up.
    'restrict_orders' => env('SHOPIFY_RESTRICT_ORDERS', false),

    // How your order numbers start ("#" for #1001, "SHOP-" for SHOP-1001).
    'order_prefix' => env('SHOPIFY_ORDER_PREFIX', '#'),
];
