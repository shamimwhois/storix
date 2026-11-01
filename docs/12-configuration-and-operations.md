# 12 — Configuration and Operations

## 1. Example configuration

The following excerpt matches the keys currently shipped by `config/storix.php`:

```php
return [
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
```

These are the package's current defaults, not a production sizing recommendation. The local driver root is private application storage. The configured chunk and parallel limits do not imply that the Local multipart driver runs parallel workers; its current advertised concurrency is one. Telegram and other remote driver settings are not shipped in the current config and must not be assumed available.

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

Preview aggregate recovery counts without changing records:

```bash
php artisan storix:transfers:recover --dry-run
```

The dry-run reports aggregate counts only. It does not list individual eligible transfers, identify which cleanup actions would run, or preview per-record changes.

Run a bounded recovery pass:

```bash
php artisan storix:transfers:recover --limit=100 --stale-seconds=300
```

The command reports examined, recovered, cleaned, expired, waiting, cleanup-pending, skipped, terminated, and failed counts. `terminated` means a transfer was successfully cleaned and deliberately marked failed after permanent failure or attempt exhaustion; it is not a command error. `failed` is reserved for reconciliation exceptions and is what causes a non-zero exit. It verifies already-published objects before promoting them, and cleans terminal multipart sessions without deleting files already marked available. Retry deadlines are persisted; an early idempotent resume is rejected until the deadline passes.

A recovery command cannot recreate source bytes that the application did not persist. A retry-ready upload requires the caller to submit the original source stream again. A long synchronous provider call cannot be heartbeated while PHP is blocked inside that call; configure driver-level network timeouts shorter than the lease lifetime. `UploadManager` renews the resume lease while hashing the read-back verification stream using `heartbeat_interval_seconds`. Keep that interval materially shorter than `resume_lease_seconds` and `recovery_lease_seconds`, leaving enough time for a slow stream read before the first heartbeat. The shared `resume_lease_grace_seconds` setting delays takeover but is not a hard fence: a worker stalled after its last renewal may still complete an already-issued provider write, abort, or delete after takeover. Lease expiry and network timeouts do not cancel a request already accepted by a provider. Token checks fence database finalization and late-publication markers support later cleanup, but strict no-overlap guarantees require storage-level fencing (such as immutable per-attempt keys and conditional operations); process termination and network timeouts alone cannot provide that guarantee.

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
