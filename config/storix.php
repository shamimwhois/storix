<?php

declare(strict_types=1);

return [
    // Default driver resolution is provided by E02; the Local adapter is registered in E04.
    'default' => env('STORIX_DRIVER', 'local'),

    'drivers' => [
        'local' => [
            'root' => storage_path('app/storix'),
        ],
    ],

    'uploads' => [
        'max_file_size_bytes' => 64 * 1024 * 1024,
        'spool_memory_bytes' => 8 * 1024 * 1024,
    ],

    // Reserved defaults; chunking and automatic retries are implemented in later epics.
    'transfers' => [
        'chunk_size' => 8 * 1024 * 1024,
        'max_parallel_chunks' => 3,
        'max_attempts' => 5,
        'resume_lease_seconds' => 300,
    ],
];
