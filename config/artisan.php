<?php

return [
    'route' => [
        'prefix' => '_artisan',
    ],
    'connection' => env('ARTISAN_CONNECTION', env('QUEUE_CONNECTION', 'cloudtasks')),
    'queue' => env('ARTISAN_QUEUE', 'artisan'),
];
