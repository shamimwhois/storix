# E08 — Recovery & Retry

## Status

Implementation and review fixes are in progress on `feature/e08-recovery-retry`, stacked on E07. CI run `41249396407` passed the SQLite, MySQL 8.4, and PostgreSQL 17 test jobs at commit `6f8ad1e`. Additional CodeRabbit-driven correctness fixes and regression tests have been committed after that run; fresh CI validation is pending. Keep PR #8 draft until the latest head passes CI and the review findings are resolved.

The E08 migration rollback/reapply and preservation tests are included in the passing CI matrix. E08 is ready for final review, but is not merged or release-complete. Do not merge this branch directly into `main`; E07 remains stacked on E06.

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
baseDelay = min(maxDelay, baseSeconds * 2^(attempt - 1))
spread    = floor(baseDelay * jitterPercent / 100)
delay     = random(max(0, baseDelay - spread), min(maxDelay, baseDelay + spread))
```

Storix persists the resulting deadline in `storix_transfers.next_retry_at`. A retry request with the same idempotency key and fingerprint receives `UPLOAD_IN_PROGRESS` while that deadline is in the future. Once the deadline passes, the caller can submit the original source stream again. A `RETRY_WAIT` transfer with an existing multipart session resumes that session and reconciles persisted chunks, reusing provider-verified parts where possible. Only a sessionless `RETRY_WAIT` transfer starts a fresh multipart attempt: it reuses the scoped file row when available (or recreates it when missing) and clears stale chunk state.

Bounded retries inside `UploadManager::upload()` currently apply to interrupted resumes of an existing multipart session. A transient failure during an initial upload or a sessionless fresh attempt is marked failed rather than scheduled for automatic retry; resubmitting that terminal transfer with the same idempotency key returns an idempotency conflict. Recovery can schedule source retry only for eligible nonterminal transfers, and it never stores or recreates the original source bytes.

The package does not persist the original upload bytes. A scheduled recovery run cannot invent or reconstruct a lost source stream. Automatic queue orchestration for source re-submission remains outside this milestone.

## Recovery lease and heartbeat

Recovery claims a transfer using the existing token-owned resume lease. The repository conditionally claims only a lease that expired before the configured `resume_lease_grace_seconds` cutoff, then renews it while long-running verification is in progress. UploadManager uses the same grace cutoff. The final file-availability transaction locks the transfer row and verifies the current token before committing completion. If an old worker returns after losing ownership, it cannot commit file availability and marks `cleanup_pending` so any late provider publication is reconciled by a subsequent sweep.

The recovery command is bounded by a maximum candidate count. It does not mutate transfers in dry-run mode. Terminal transfers that still contain a multipart upload identifier can be leased for cleanup without changing their terminal state.

Heartbeat renewal during recovery checksum streaming and UploadManager's provider read-back verification uses `heartbeat_interval_seconds`; keep this interval materially shorter than the corresponding lease duration, allowing time for a slow stream read before the first heartbeat. Each blocking provider call or stream read must still return within the lease window because PHP cannot heartbeat while blocked inside that call. Configure driver/network read timeouts accordingly. The grace interval gives a returning stale worker time to observe lease loss and record a late-publication cleanup marker; it is not a substitute for a bounded provider timeout. Provider-specific cancellation and background heartbeat workers are future hardening.

## Post-provider-completion crash recovery

A process may crash after a provider publishes the final object but before Storix commits the file and transfer as completed. Recovery checks the existing file record, driver, expected byte count, and persisted SHA-256, then streams the stored object and independently recomputes its digest.

Only an exact size and checksum match allows recovery to mark the file available and the transfer completed. Missing or mismatched objects are not promoted. A mismatched unpublished object is removed before the transfer is deferred or expired.

## Stale and terminal session cleanup

The recovery command examines stale active transfers and terminal records that carry a multipart session identifier or the durable `cleanup_pending` marker.

- An expired active transfer is cleaned before the transfer is marked expired.
- A transfer that exhausted its retry budget, or has a known permanent error, is cleaned and marked failed.
- A terminal transfer with a leftover multipart identifier has that session cleaned without deleting a file already marked available.
- A terminal failed transfer with an object-delete failure retains `cleanup_pending`; later sweeps retry deleting that unpublished object even when no multipart identifier remains.
- Cleanup failures leave the multipart identifier or cleanup marker intact for a later recovery attempt; a successful cleanup clears the marker so it is not scanned indefinitely.
- If a terminal cleanup provider call outlives its recovery lease and then fails, the catch path restores `cleanup_pending` without requiring lease ownership; the next sweep still reclaims the transfer through the normal lease/grace checks.

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

The command prints counts for examined, recovered, cleaned, expired, waiting, cleanup-pending, skipped, terminated, and failed transfers. `terminated` counts transfers successfully cleaned and deliberately moved to `FAILED` because retries were exhausted or the error was permanent; this is a handled outcome and does not make the command fail. `failed` is reserved for reconciliation exceptions and causes a non-zero exit. A non-zero exit therefore signals recovery work that could not be reconciled, not merely a transfer that was correctly terminated; inspect sanitized application logs.

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
| `heartbeat_interval_seconds` | 15 | Minimum interval between streamed recovery heartbeat updates |
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
