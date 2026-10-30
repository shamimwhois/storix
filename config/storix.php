<?php

declare(strict_types=1);

return [
    // E01 configuration defaults; driver resolution is introduced in E02/E04.
    'default' => env('STORIX_DRIVER', 'local'),

    'drivers' => [
        'local' => [
            'root' => storage_path('app/storix'),
        ],
    ],

    // Reserved defaults; E01 does not execute transfers or chunking.
    'transfers' => [
        'chunk_size' => 8 * 1024 * 1024,
        'max_parallel_chunks' => 3,
        'max_attempts' => 5,
    ],
];
