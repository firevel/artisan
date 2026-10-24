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

    /*
    |--------------------------------------------------------------------------
    | Remote execution (php artisan firevel:artisan)
    |--------------------------------------------------------------------------
    |
    | Defaults for the local helper command that runs artisan on a deployed
    | instance. When `url` is empty the App Engine host is built from
    | `project` and `service`, falling back to app.yaml and gcloud config.
    | `token` is optional; without it the token comes from
    | `gcloud auth print-access-token`.
    |
    */
    'remote' => [
        'url' => env('ARTISAN_REMOTE_URL'),
        'project' => env('GOOGLE_CLOUD_PROJECT'),
        'service' => env('GAE_SERVICE'),
        'token' => env('ARTISAN_REMOTE_TOKEN'),
        'timeout' => env('ARTISAN_REMOTE_TIMEOUT', 600),
    ],
];
