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

## 2. E08 recovery and retry

The implemented recovery settings live under `storix.transfers`:

```php
'transfers' => [
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
```

Inspect eligible records before cleanup:

```bash
php artisan storix:transfers:recover --dry-run
```

Run a bounded recovery pass:

```bash
php artisan storix:transfers:recover --limit=100 --stale-seconds=300
```

The command reports examined, recovered, cleaned, expired, waiting, skipped, and failed counts. It verifies already-published objects before promoting them, and cleans terminal multipart sessions without deleting files already marked available. Retry deadlines are persisted; an early idempotent resume is rejected until the deadline passes.

A recovery command cannot recreate source bytes that the application did not persist. A retry-ready upload requires the caller to submit the original source stream again. A long synchronous provider call cannot be heartbeated while PHP is blocked inside that call; configure driver-level network timeouts shorter than the lease lifetime. The shared `resume_lease_grace_seconds` setting delays takeover after expiry and gives a returning stale worker time to mark any late provider publication for cleanup.

For periodic cleanup, use Laravel's scheduler with overlap prevention after validating dry-run output:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('storix:transfers:recover --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

## 3. Queue operations

- Use Laravel queues for long-running transfers.
- Configure dedicated queue names for transfers and cleanup.
- Set worker timeout longer than expected job execution but shorter than process supervisor limits.
- Ensure `retry_after` exceeds job timeout.
- Use unique locks and idempotent jobs.
- Document Horizon only as an optional operational choice.

## 4. Scheduler commands

Potential commands:
- `storix:transfers:recover` — reconcile stale sessions.
- `storix:transfers:cleanup` — expire old sessions and temporary files.
- `storix:storage:verify` — sample or scheduled integrity checks.
- `storix:storage:prune` — remove eligible orphaned objects.

Commands must support dry-run where destructive cleanup is involved.

## 5. Observability

Log structured fields: transfer ID, file ID, driver, direction, state, attempt, chunk index, duration, byte count, error code and request ID. Exclude credentials and raw file contents. Provide hooks for Laravel events and metrics exporters.

## 6. Troubleshooting

- **Transfer stuck:** inspect queue worker health, lock expiry, last heartbeat and retry state.
- **Repeated retries:** inspect sanitized error code, provider quota and retry-after metadata.
- **Disk full:** stop scheduling new local transfers, alert, clean eligible temp files.
- **Checksum mismatch:** quarantine the object/chunk, retry from source if available, never mark complete.
- **Missing provider object:** reconcile metadata and remote state; do not trust stale database references.
- **High memory:** confirm streaming path; inspect chunk buffering and concurrent worker count.


---
