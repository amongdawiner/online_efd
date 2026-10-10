<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    | Receipt date/time, Z-numbers and the daily counter reset all use this
    | timezone (TRA expects Tanzanian local time).
    */
    'timezone' => env('EFD_TIMEZONE', 'Africa/Dar_es_Salaam'),

    /*
    |--------------------------------------------------------------------------
    | TRA API
    |--------------------------------------------------------------------------
    */
    'api' => [
        'base_url' => env('EFD_BASE_URL', 'https://virtual.tra.go.tz/efdmsRctApi'),
        'verify_ssl' => env('EFD_VERIFY_SSL', true),
        'timeout' => 30,
        'client' => 'WEBAPI',

        'endpoints' => [
            'registration' => '/api/vfdRegReq',
            'token' => '/vfdtoken',
            'receipt' => '/api/efdmsRctInfo',
            'zreport' => '/api/efdmszreport',
        ],

        'routing_keys' => [
            'receipt' => 'vfdrct',
            'zreport' => 'vfdzreport',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default VAT rates (percent) per TRA VAT rate letter
    |--------------------------------------------------------------------------
    | Overridden per taxpayer by the rates TRA returns on registration.
    */
    'tax_rates' => [
        'A' => 18,
        'B' => 0,
        'C' => 0,
        'D' => 0,
        'E' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue (used by Efd::for($owner)->issueReceiptLater())
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => null,
        'queue' => null,
        'tries' => 5,
        'backoff' => [30, 120, 600],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public receipt verification page
    |--------------------------------------------------------------------------
    */
    'verification_url' => 'https://verify.tra.go.tz',
];
