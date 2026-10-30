# 12 — Configuration and Operations

## 1. Example configuration

```php
return [
    'default_driver' => env('STORIX_DRIVER', 'local'),

    'drivers' => [
        'local' => [
            'disk' => env('STORIX_LOCAL_DISK', 'local'),
            'prefix' => 'storix',
        ],
        'telegram' => [
            'enabled' => env('STORIX_TELEGRAM_ENABLED', false),
            'bot_token' => env('STORIX_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('STORIX_TELEGRAM_CHAT_ID'),
            'api_base' => env('STORIX_TELEGRAM_API_BASE'),
        ],
    ],

    'transfers' => [
        'chunk_size' => 8 * 1024 * 1024,
        'max_concurrency' => 3,
        'max_attempts' => 5,
        'backoff_base_seconds' => 2,
        'backoff_max_seconds' => 300,
        'session_ttl_hours' => 24,
        'progress_persist_interval_ms' => 1000,
    ],

    'limits' => [
        'max_file_size' => 10 * 1024 * 1024 * 1024,
        'max_active_transfers_per_owner' => 5,
        'temporary_disk_min_free_bytes' => 512 * 1024 * 1024,
    ],

    'api' => [
        'enabled' => false,
        'prefix' => 'api/v1/storix',
        'middleware' => ['api', 'auth:sanctum'],
    ],
];
```

These are illustrative defaults, not final values. Validate every setting and align limits with actual driver capability.

## 2. Queue operations

- Use Laravel queues for long-running transfers.
- Configure dedicated queue names for transfers and cleanup.
- Set worker timeout longer than expected job execution but shorter than process supervisor limits.
- Ensure `retry_after` exceeds job timeout.
- Use unique locks and idempotent jobs.
- Document Horizon only as an optional operational choice.

## 3. Scheduler commands

Potential commands:
- `storix:transfers:recover` — reconcile stale sessions.
- `storix:transfers:cleanup` — expire old sessions and temporary files.
- `storix:storage:verify` — sample or scheduled integrity checks.
- `storix:storage:prune` — remove eligible orphaned objects.

Commands must support dry-run where destructive cleanup is involved.

## 4. Observability

Log structured fields: transfer ID, file ID, driver, direction, state, attempt, chunk index, duration, byte count, error code and request ID. Exclude credentials and raw file contents. Provide hooks for Laravel events and metrics exporters.

## 5. Troubleshooting

- **Transfer stuck:** inspect queue worker health, lock expiry, last heartbeat and retry state.
- **Repeated retries:** inspect sanitized error code, provider quota and retry-after metadata.
- **Disk full:** stop scheduling new local transfers, alert, clean eligible temp files.
- **Checksum mismatch:** quarantine the object/chunk, retry from source if available, never mark complete.
- **Missing provider object:** reconcile metadata and remote state; do not trust stale database references.
- **High memory:** confirm streaming path; inspect chunk buffering and concurrent worker count.


---
