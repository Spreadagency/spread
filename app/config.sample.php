<?php
/**
 * Copy this file to app/config.php and fill it in.
 * Only DB credentials and the app key live here — every other setting
 * (texts, API keys, pixels, limits…) is stored in the `settings` table
 * and edited from the admin panel.
 */
return [
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'cpaneluser_slim',
        'user'    => 'cpaneluser_slim',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // 64 random hex chars. Generate with:
    //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Changing it later makes stored secrets (API keys) unreadable.
    'app_key' => 'CHANGE_ME_TO_64_HEX_CHARS',

    // Public URL of the subdomain, no trailing slash. Leave empty to auto-detect.
    'app_url' => 'https://slim.doctor-domain.com',

    // Set true when the site sits behind Cloudflare so the real visitor IP
    // is read from CF-Connecting-IP (needed for rate limits and CAPI).
    'behind_cloudflare' => false,

    // Show PHP errors in the browser. Keep false in production.
    'debug' => false,
];
