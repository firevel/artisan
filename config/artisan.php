<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Prefix for routes used to make artisan calls.
    |--------------------------------------------------------------------------
    |
    | Currently supported calls are:
    | - /{prefix}/call
    | - /{prefix}/queue
    |
    */
    'route' => [
        'prefix' => '_artisan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Header used for request authorization.
    |--------------------------------------------------------------------------
    |
    | By default `Authorization: Bearer` will be used to pass access token.
    |
    | In case of conflict you can setup a custom header here.
    |
    */
    'authorization_header' => 'Authorization',

    /*
    |--------------------------------------------------------------------------
    | Service Accounts authorized .
    |--------------------------------------------------------------------------
    |
    | By default allow default App Engine service account.
    |
    | Can be used to handle Cloud Scheduler OIDC token.
    |
    */
    'authorized_service_accounts' => [
        env('GOOGLE_CLOUD_PROJECT') . '@appspot.gserviceaccount.com',
    ],

    // Queue connection.
    'connection' => env('ARTISAN_CONNECTION', env('QUEUE_CONNECTION', 'cloudtasks')),
    // Queue name.
    'queue' => env('ARTISAN_QUEUE', 'artisan'),
];
