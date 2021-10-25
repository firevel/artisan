<?php

return [
    // Prefix for routes used to make artisan calls.
    'route' => [
        'prefix' => '_artisan',
    ],

    // Token used for authentication.
    'token_header' => 'x-GCP-token',

    // Queue connection.
    'connection' => env('ARTISAN_CONNECTION', env('QUEUE_CONNECTION', 'cloudtasks')),
    // Queue name.
    'queue' => env('ARTISAN_QUEUE', 'artisan'),
];
