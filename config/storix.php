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

    'downloads' => [
        'max_size_bytes' => 1024 * 1024 * 1024,
        'max_concurrent_downloads' => 1,
        'lock_ttl_seconds' => 86_400,
        'cache_store' => null,
        'spool_memory_bytes' => 8 * 1024 * 1024,
    ],

    // Transfer resource and recovery limits.
    'transfers' => [
        'chunk_size' => 8 * 1024 * 1024,
        'max_parallel_chunks' => 3,
        'max_attempts' => 5,
        'resume_lease_seconds' => 300,
        'resume_lease_grace_seconds' => 60,
        'recovery_lease_seconds' => 300,
        'recovery_stale_seconds' => 300,
        'heartbeat_interval_seconds' => 15,
        'session_ttl_hours' => 24,
        'retry_base_seconds' => 2,
        'retry_max_seconds' => 300,
        'retry_jitter_percent' => 20,
    ],
];
