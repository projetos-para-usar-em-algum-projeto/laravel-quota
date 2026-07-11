<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Quota Driver
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "cache", "database"
    |
    */
    'default' => env('QUOTA_DRIVER', 'cache'),

    /*
    |--------------------------------------------------------------------------
    | Quota Drivers Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the storage parameters for each supported driver.
    |
    */
    'drivers' => [
        'cache' => [
            'driver' => 'cache',
            'store' => env('QUOTA_CACHE_STORE', null),
            'prefix' => 'quotas:',
        ],
        'database' => [
            'driver' => 'database',
            'table' => 'quotas',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quota Events Dispatching
    |--------------------------------------------------------------------------
    |
    | Determine whether the package should dispatch events when quotas are
    | consumed, exceeded, or reset.
    |
    */
    'events' => [
        'dispatch' => true,
    ],
];
