# E08 — Recovery & Retry

## Status

E08 is integrated on top of the E07 merge commit through PR [#16](https://github.com/shamimwhois/storix/pull/16), from branch `integration/e08-on-main`. The current integration head is undergoing code and security review. The PR's SQLite, MySQL 8.4 and PostgreSQL 17 CI matrix passed before the latest review-driven fixes; those changes require a fresh current-head run. E08 is not merge-complete until the updated CI matrix passes and the review findings are resolved.

The integration preserves the fail-closed `cleanup_pending` marker during provider I/O and after a point-in-time missing-object stat. It also reserves recovery batch capacity for stale active transfers so terminal cleanup backlog cannot consume the entire pass.

## Goals

- Distinguish transient, rate-limited, permanent, and unknown failures without logging provider secrets or raw exception messages.
- Apply a bounded exponential retry delay with configurable jitter and a persisted `next_retry_at`.
- Prevent an idempotent resume request from bypassing its retry deadline.
- Use an atomic transfer lease and heartbeat while the recovery process owns a transfer.
- Delay lease takeover until a configurable `resume_lease_grace_seconds` interval after expiry, and lock the transfer row while upload finalization verifies its owner token.
- Recover the narrow crash window where the provider has published the final object but the database still records an unfinished transfer.
- Expire abandoned sessions and safely clean multipart workspaces left by active or terminal transfers.
- Make recovery observable and bounded through a Laravel console command with a dry-run option.

## Failure classification

`ErrorClassifier` provides four categories:

| Category | Meaning | Recovery behaviour |
|---|---|---|
| `transient` | Temporary storage, network, or database failure | Eligible for bounded retry |
| `rate_limited` | Provider returned HTTP 429 | Eligible for bounded retry |
| `permanent` | Invalid input, unsupported capability, integrity violation, or permanent provider response | Do not retry automatically |
| `unknown` | An exception without a stable classification | Eligible only within the configured attempt limit |

The classifier examines wrapped exceptions and HTTP response status where available. Persisted transfer records retain stable Storix error codes, not provider response bodies, tokens, or raw exception messages.

An unknown error code is treated as unknown and remains bounded by `max_attempts`. A known permanent code is not rescheduled.

## Retry policy

The delay is calculated as capped exponential backoff with bounded jitter:

```text
baseDelayMs = min(maxDelaySeconds * 1000, baseSeconds * 1000 * 2^(attempt - 1))
spreadMs    = floor(baseDelayMs * jitterPercent / 100)
delayMs     = random(max(0, baseDelayMs - spreadMs), min(maxDelaySeconds * 1000, baseDelayMs + spreadMs))
```

Storix persists the resulting deadline in `storix_transfers.next_retry_at`. A retry request with the same idempotency key and fingerprint receives `UPLOAD_IN_PROGRESS` while that deadline is in the future. Passing the deadline is necessary but not sufficient: `UploadManager::upload()` also rejects transfers with `cleanup_pending` or an expired session. Callers should inspect the final transfer state before resubmitting the original source stream. If recovery transitions the transfer to `EXPIRED` because no verified object can be promoted, the transfer is terminal: resubmitting with the same idempotency key returns an idempotency conflict, and later cleanup does not make that key retryable; use a new idempotency key for a new upload. For a nonterminal transfer held by `cleanup_pending`, repeated submissions do not advance the attempt and remain blocked until the possible late-publication risk is safely resolved. A `RETRY_WAIT` transfer with an existing multipart session resumes that session and reconciles persisted chunks, reusing provider-verified parts where possible. Only a sessionless `RETRY_WAIT` transfer starts a fresh multipart attempt: it reuses the scoped file row when available (or recreates it when missing) and clears stale chunk state.

Bounded retries inside `UploadManager::upload()` currently apply to interrupted resumes of an existing multipart session. A transient failure during an initial upload or a sessionless fresh attempt is marked failed rather than scheduled for automatic retry; resubmitting that terminal transfer with the same idempotency key returns an idempotency conflict. Recovery can schedule source retry only for eligible nonterminal transfers, and it never stores or recreates the original source bytes.

The package does not persist the original upload bytes. A scheduled recovery run cannot invent or reconstruct a lost source stream. Automatic queue orchestration for source re-submission remains outside this milestone.

## Recovery lease and heartbeat

Recovery claims a transfer using the existing token-owned resume lease. The repository conditionally claims only a lease that expired before the configured `resume_lease_grace_seconds` cutoff, then renews it while long-running verification is in progress. UploadManager uses the same grace cutoff. The final file-availability transaction locks the transfer row and verifies the current token before committing completion. If an old worker returns after losing ownership, it cannot commit file availability and marks `cleanup_pending` so a subsequent sweep can attempt cleanup of unpublished storage. A completed transfer with an available file is preserved without provider revalidation.

The recovery command is bounded by a maximum candidate count. It does not mutate transfers in dry-run mode. Terminal transfers that still contain a multipart upload identifier can be leased for cleanup without changing their terminal state.

Heartbeat renewal during recovery checksum streaming and UploadManager's provider read-back verification uses `heartbeat_interval_seconds`; keep this interval materially shorter than the corresponding lease duration, allowing time for a slow stream read before the first heartbeat. Each blocking provider call or stream read must still return within the lease window because PHP cannot heartbeat while blocked inside that call. Configure driver/network read timeouts accordingly. The grace interval delays takeover but is not a hard fence: a worker stalled after its last renewal may still have an already-issued provider write, abort, or delete complete after another worker takes over. Token checks prevent stale database finalization and late-publication markers make many late writes discoverable, but they cannot cancel or order an in-flight provider operation. Process termination and network timeouts do not cancel a request already accepted by a provider. A single missing-object stat is therefore not proof that an accepted provider operation can no longer publish. Storix keeps `cleanup_pending` fail-closed for possible late publication rather than clearing it solely because one recovery observation found no object; this may hold a retry until the risk can be resolved safely. Strict no-overlap guarantees require storage-level fencing (for example, immutable per-attempt object keys, isolated multipart sessions, and conditional publication/deletion). This is tracked separately in [issue #11](https://github.com/shamimwhois/storix/issues/11); E08's database lease is not that fence.

## Post-provider-completion crash recovery

A process may crash after a provider publishes the final object but before Storix commits the file and transfer as completed. Recovery checks the existing file record, driver, expected byte count, and persisted SHA-256, then streams the stored object and independently recomputes its digest.

Only an exact size and checksum match allows recovery to mark the file available and the transfer completed. Missing or mismatched objects are not promoted. A mismatched unpublished object is removed before the transfer is deferred or expired.

## Stale and terminal session cleanup

The recovery command examines stale active transfers and terminal records that carry a multipart session identifier or the durable `cleanup_pending` marker.

- An expired active transfer is cleaned and marked expired only when recovery cannot find a verified published object.
- A transfer that exhausted its retry budget, or has a known permanent error, is cleaned and marked failed only when recovery cannot find a verified published object.
- A verified published object is promoted to an available file and completed transfer even if the session has expired or the retry budget is exhausted; recovery must never delete that verified object as abandoned work.
- A terminal transfer with a leftover multipart identifier has that session cleaned without deleting a file already marked available.
- A terminal failed transfer with an object-delete failure retains `cleanup_pending`; later sweeps retry deleting that unpublished object even when no multipart identifier remains.
- The marker stays set throughout provider I/O. If a sweep finds no object, it retains the marker because a provider write accepted earlier may still publish later; a subsequent sweep can discover and remove that late object.
- The marker is cleared only after cleanup has actually located and removed the unpublished object (or safely preserved an already-available file while aborting its leftover session). A missing-object stat alone never clears it. When the provider cannot establish that cleanup is safe, the row remains visible to later sweeps and a retry may remain blocked indefinitely pending stronger storage-level fencing or operator resolution.
- If a terminal cleanup provider call outlives its recovery lease and then fails, the catch path preserves `cleanup_pending` without requiring lease ownership; the next sweep still reclaims the transfer through the normal lease/grace checks.

The Local driver treats abort of a missing multipart workspace as idempotent. Remote providers must implement equivalent safe abort semantics before they are considered compatible with this recovery flow.

## Command

Preview aggregate recovery counts without mutation. Dry-run does not list individual transfers or predict per-record cleanup actions:

```bash
php artisan storix:transfers:recover --dry-run
```

Run a bounded recovery pass:

```bash
php artisan storix:transfers:recover --limit=100 --stale-seconds=300
```

The command prints counts for examined, recovered, cleaned, expired, waiting, cleanup-pending, skipped, terminated, and failed transfers. `terminated` counts transfers successfully cleaned and deliberately moved to `FAILED` because retries were exhausted or the error was permanent; this is a handled outcome and does not make the command fail. `failed` counts reconciliation exceptions that could not be deferred or scheduled for cleanup retry and causes a non-zero exit. Exceptions successfully deferred for retry are counted as `waiting` or `cleanup-pending` and do not cause a non-zero exit. Review all result counts and sanitized application logs, not only the exit code.

A Laravel scheduler may run the command periodically, with overlap prevention:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('storix:transfers:recover --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

Start with dry-run in an environment containing existing transfers. Review the candidate counts and storage cleanup behaviour before enabling the scheduled command.

## Configuration

The package configuration under `storix.transfers` includes:

| Key | Default | Purpose |
|---|---:|---|
| `max_attempts` | 5 | Maximum upload/resume attempts |
| `resume_lease_seconds` | 300 | Upload/resume lease duration |
| `resume_lease_grace_seconds` | 60 | Additional time after lease expiry before another worker may take over |
| `recovery_lease_seconds` | 300 | Recovery lease duration |
| `recovery_stale_seconds` | 300 | Minimum stale age used by the command |
| `heartbeat_interval_seconds` | 15 | Minimum interval between streamed recovery and upload read-back heartbeat updates |
| `session_ttl_hours` | 24 | Default age after which an unfinished transfer expires |
| `retry_base_seconds` | 2 | First retry delay before jitter |
| `retry_max_seconds` | 300 | Hard maximum for the final jittered retry delay |
| `retry_jitter_percent` | 20 | Jitter percentage, bounded to 0–100 |

The effective upload size remains constrained by the configured package limit and the selected driver's advertised maximum object size.

## Required verification

- Transient, rate-limited, permanent, and unknown failure classification.
- Maximum attempts and backoff bounds, including persisted retry deadline enforcement.
- A second worker cannot take a live recovery lease.
- A provider-published object is recovered after a simulated database-finalization interruption only when size and SHA-256 match.
- A corrupt or incomplete final object is never marked available.
- Stale session cleanup is safe and idempotent.
- Terminal multipart cleanup never deletes an already available file.
- A terminal object-delete failure after recovery lease expiry preserves `cleanup_pending` and succeeds on a later sweep.
- Dry-run performs no lease acquisition, database updates, or storage cleanup.
- E08 migration rollback/reapply preserves existing transfer rows.
- CI passes on SQLite, MySQL 8.4, and PostgreSQL 17.

## Explicitly deferred

- Reconstructing a missing client source stream or resuming an upload without the caller resubmitting it.
- Queue-based worker orchestration and automatic background re-submission.
- Driver-level heartbeat/cancellation while a synchronous provider call is blocked.
- Remote-provider multipart integration and provider-specific retry-after scheduling.
- Download recovery, download retries, and stale download cleanup.
- Distributed performance/load tests and production recovery exercises.
