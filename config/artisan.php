<?php

return [
    // Prefix for routes used to make artisan calls.
    'route' => [
        'prefix' => '_artisan',
    ],

    // Header used for requesr authorization.
    'authorization_header' => 'Authorization',

    // Queue connection.
    'connection' => env('ARTISAN_CONNECTION', env('QUEUE_CONNECTION', 'cloudtasks')),
    // Queue name.
    'queue' => env('ARTISAN_QUEUE', 'artisan'),
];
