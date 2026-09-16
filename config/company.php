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
];
