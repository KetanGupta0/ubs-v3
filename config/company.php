<?php

/*
|--------------------------------------------------------------------------
| Company details
|--------------------------------------------------------------------------
|
| Starting values only. Once somebody saves the settings screen the stored
| values win, and these are just what a fresh installation shows.
|
| They live here rather than being read from the environment at the point of
| use, because env() returns null once the configuration is cached, and these
| would silently become blank in production.
|
*/

return [
    'name' => env('COMPANY_NAME', 'Unboundbyte Solutions'),
    'legal_name' => env('COMPANY_LEGAL_NAME', 'Unboundbyte Solutions Private Limited'),
    'email' => env('COMPANY_EMAIL'),
    'phone' => env('COMPANY_PHONE'),
    'address' => env('COMPANY_ADDRESS'),
    'gstin' => env('COMPANY_GSTIN'),
    'cin' => env('COMPANY_CIN'),
    'pan' => env('COMPANY_PAN'),

    /*
    | Sending credentials to many accounts at once, such as everybody brought
    | across from the old platform. Spread out so a mail or SMS provider's
    | rate limit does not fail half the list.
    */
    'credentials' => [
        'bulk_per_minute' => (int) env('CREDENTIALS_BULK_PER_MINUTE', 30),
        'bulk_max' => 1000,
        // A second send inside this window is skipped, so a double click
        // does not reset somebody's password twice.
        'resend_cooldown_minutes' => 15,
    ],

    /*
    | An invoice raised by the client accepting a milestone (CL9) is due this
    | many days later. Staff raising one by hand choose the date themselves.
    */
    'billing' => [
        'milestone_due_days' => 7,
    ],

    /*
    | Signing, accepting and paying for a client organisation need two-step
    | sign in (CL20): a signature or a payment is only as trustworthy as the
    | account that made it. On unless switched off for an install.
    */
    'clients' => [
        'two_factor_to_commit' => (bool) env('CLIENT_TWO_FACTOR_REQUIRED', true),
    ],

    /*
    | Selling products (§4). Source code is kept on the hosting's private disk
    | (X16), so uploads are capped; PHP's own upload_max_filesize and
    | post_max_size must allow at least this much. Download links are signed
    | and expire, and each licence may fetch each version a limited number of
    | times. An unpaid order lapses after a while; a coupon it holds is held
    | for it for a shorter while, so an abandoned checkout does not use up a
    | limited code.
    */
    'shop' => [
        'max_upload_mb' => (int) env('SHOP_MAX_UPLOAD_MB', 100),
        'download_link_minutes' => 10,
        'downloads_per_version' => 10,
        'order_expiry_days' => 7,
        'coupon_hold_minutes' => 120,
        'licence_check_hours' => 24,
    ],

    /*
    | TDS a client may declare on one of our invoices (CL14), by section, at
    | the rate for a resident company deducting from us. Rates change with
    | Finance Acts: change them here, never in the code that applies them.
    */
    'tds' => [
        'sections' => [
            '194J-professional' => ['label' => 'Professional services, section 194J', 'rate' => 10],
            '194J-technical' => ['label' => 'Technical services, section 194J', 'rate' => 2],
            '194C' => ['label' => 'Contract work, section 194C', 'rate' => 2],
        ],
    ],
];
