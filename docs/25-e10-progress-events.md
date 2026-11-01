# E10 — Progress & Events

**Status:** M01 progress snapshot API implemented; lifecycle instrumentation and event dispatch pending  
**Base:** `feature/e09-download-engine`  
**Compatibility:** PHP 8.5+, Laravel 13  
**Dependencies:** E05–E09

## 1. Goal

Expose accurate, tenant-safe transfer progress and lifecycle notifications without coupling Storix to a frontend, queue driver, broadcaster, notification channel, or third-party telemetry service.

E10 adds immutable progress snapshots, lifecycle events, configurable persistence/dispatch throttling, and optional notification hooks. It must preserve the existing transfer state machine, idempotency, retry/recovery semantics, and E09 download integrity/resource guarantees.

## 2. Non-goals

- No dashboard, Livewire component, API route, websocket server, or UI.
- No mandatory queue, broadcast, notification, metrics, or telemetry dependency.
- No provider-specific progress API in the core contract.
- No progress claim based only on bytes read from an unverified source.
- No change to provider-level fencing guarantees; E08 database leases remain database ownership/finalization controls.
- No schema migration unless the existing transfer columns prove insufficient.

## 3. Existing persisted model

`storix_transfers` already contains `total_bytes`, `transferred_bytes`, `total_chunks`, `completed_chunks`, `average_bytes_per_second`, `eta_seconds`, `started_at`, `finished_at`, `last_heartbeat_at`, `state`, and `direction`.

Reuse these columns first. Do not add a duplicate progress table or per-second history table to the core package.

## 4. Canonical progress semantics

A snapshot represents persisted transfer state, not bytes merely read from an input stream.

- `transferred_bytes` counts bytes accepted by the current transfer attempt and confirmed by the existing verification boundary.
- A chunk contributes only after its write succeeds and size/checksum/provider reference are persisted as verified.
- Single-object upload progress must not claim verified completion before independent read-back verification. If byte-level progress cannot be measured without misrepresenting integrity, emit phase/state changes and advance bytes at the verification boundary.
- Recovery recomputes progress from verified chunks or independently verified final-object metadata; it never blindly increments counters.
- `completed_chunks` counts verified chunks only, never queued or attempted chunks.
- Unknown/zero totals produce a null percentage. Percent is clamped to 0–100 and reaches 100 only when state is `completed`.
- ETA is null until a stable positive throughput estimate exists; it is zero only after completion.
- E09 downloads currently return a stream to the caller. Default E10 scope is upload/recovery progress. Any download-side progress API must count caller-consumed bytes, not provider-read bytes, and must be explicitly opt-in.

## 5. Public API proposal

Names are provisional pending implementation:

- `Storix::progress()->forTransfer($transferId, $tenantId, $ownerId)` returns an immutable, tenant/owner-scoped `TransferProgressSnapshot`.
- Lifecycle events are normal Laravel events.
- Notification integration is a callback/contract hook; Storix itself sends no email, SMS, push, or database notifications.

Snapshot fields: transfer ID, direction, state, phase, total/transferred/remaining bytes, nullable percent, average bytes/second, nullable ETA, total/completed chunks, timestamps, and a monotonically increasing per-transfer revision.

Never expose storage keys, provider multipart IDs, resume lease tokens, idempotency secrets, raw provider errors, or private source paths.

## 6. Lifecycle events

Proposed event classes:

- `TransferStarted`
- `TransferProgressed`
- `TransferRetryScheduled`
- `TransferPaused`
- `TransferCompleted`
- `TransferFailed`
- `TransferCancelled`
- `TransferExpired`

Each event carries an immutable sanitized snapshot and stable transfer ID. Dispatch after the corresponding DB transaction commits; rolled-back transactions must not leak events.

Ordering/invariants:

1. Start event follows persistence of the initial state.
2. Progress bytes/chunks/revisions are monotonic within one attempt.
3. Retry events expose retry state/deadline, never lease tokens/provider internals.
4. Terminal events are emitted once for the winning state transition.
5. Recovery of an already-completed transfer must not emit duplicate completion.
6. Queued listeners must tolerate at-least-once delivery and deduplicate by transfer ID + event type + revision/state transition identity.

## 7. Speed and ETA

- Use monotonic elapsed time for in-process samples.
- Persist a smoothed bytes-per-second estimate, not a tiny interval's instantaneous rate.
- Ignore zero/negative elapsed intervals and clock anomalies; never divide by zero.
- Re-baseline after worker restart; do not include queue wait, retry backoff, or downtime in throughput.
- ETA uses remaining bytes divided by a valid smoothed rate and is capped to a safe integer range.

## 8. Throttling and configuration

Add `storix.progress` configuration:

- `enabled` (default true for lifecycle events; progress persistence independently configurable)
- `persist_interval_ms` (default 1000)
- `dispatch_interval_ms` (default 1000)
- `min_bytes_delta` (default 1 MiB)
- `speed_sample_window` (bounded sample count)
- `dispatch_terminal_events` (default true)

Persist/dispatch when either interval or byte-delta threshold is reached. Always persist terminal state/final counters. Never throttle state transitions, retry scheduling, or terminal events. Use in-process throttling for one worker and database-level conditional updates for cross-worker correctness. Cache locks are not correctness invariants.

Progress writes must be attempt/lease scoped wherever E07/E08 establish a transfer lease. A stale worker cannot overwrite a newer attempt's counters or emit a higher revision. Progress-only updates never change transfer state; state changes remain exclusively through `TransferStateMachine`. If an operation has no lease, use its existing atomic ownership contract—do not invent weaker locking.

Synchronous dispatch is the default and must remain lightweight. Queue dispatch is opt-in.

## 9. Notification hooks

- Provide an optional integration point for host applications to map events to Laravel Notifications, mail, broadcast, or custom systems.
- Send no notifications automatically.
- Include no user email/phone/address in event payloads.
- Host applications decide recipients and authorization.
- Notification failure must not fail or roll back a storage transfer.
- Queued payloads must be serializable and contain no open streams or secret-bearing Eloquent model graphs.

## 10. Testing plan

### Snapshot/API
- Wrong tenant/owner, missing transfer, and unscoped access are rejected.
- Null/zero totals yield null percent; valid percent is clamped.
- ETA is null without a valid rate and zero only on completion.
- Payload contains no provider secrets or storage keys.

### Accuracy/recovery
- Verified chunk increments bytes/chunks exactly once.
- Duplicate callbacks do not double-count.
- Failed/unverified chunks do not advance verified progress.
- Recovery rebuilds counters from verified chunk records.
- Single-object upload does not report completion before independent integrity verification.
- Rate/ETA handle zero elapsed time, tiny samples, retry, and worker restart.

### Events
- Lifecycle events fire after commit only; rollback emits none.
- State transition emits one event.
- Terminal events are not duplicated by recovery.
- Revisions remain monotonic under concurrent workers.
- Event ordering remains correct across retry/recovery.
- Dispatch failure does not fail transfer completion.

### Throttling/concurrency
- Interval and byte-delta throttles both work.
- State/terminal events bypass throttling.
- Stale lease token cannot update progress or dispatch a higher revision.
- Concurrent workers cannot lose increments or overcount chunks.
- Queued listeners receive serializable payloads.

### Compatibility
- SQLite, MySQL 8.4, PostgreSQL 17; PHP 8.5; Laravel 13.
- E05–E09 regression suites remain green.
- No required Redis, queue, broadcast, or notification driver.

## 11. Implementation sequence

1. Audit all progress writes and lease ownership paths across upload, recovery, retry, and chunk reconciliation.
2. Define immutable snapshot DTO and scoped ProgressManager read API.
3. Add atomic progress repository operations with attempt/lease fencing.
4. Add rate/ETA calculator with deterministic clock injection.
5. Add lifecycle event classes and after-commit dispatch.
6. Instrument only verified progress boundaries in upload/chunk/recovery paths.
7. Add throttling config and terminal-event bypass.
8. Add optional notification integration documentation/examples.
9. Add cross-database concurrency/event tests.
10. Run the full four-way CI matrix and independent code/security review.

## 12. Acceptance criteria

E10 is complete only when progress is derived from persisted/verified state; stale workers cannot regress counters or emit newer revisions; lifecycle/terminal events are transactionally ordered and not duplicated; throttling bounds write/dispatch volume without hiding state transitions; ETA is deterministic and restart-safe; snapshots/events are tenant-safe and secret-free; integrations remain optional; all E05–E09 regression suites and the SQLite/MySQL/PostgreSQL/Windows CI matrix pass; and all review findings are resolved.

## 13. Current implementation status

### M01 — Scoped progress snapshot API

Implemented on `feature/e10-progress-events`:

- Add `progress_revision` to `storix_transfers` with a reversible migration; default is zero for existing rows.
- Add the `TransferProgressSnapshot` immutable DTO.
- Add `ProgressManager::forTransfer()` using the existing tenant/owner-scoped repository lookup.
- Expose `Storix::progress()` through the manager, service provider, and facade.
- Add feature tests for scope enforcement, percentage, remaining bytes, ETA, completion semantics, and nonterminal totals.

M01 intentionally provides read-only snapshots. Atomic revision increments, progress writes, rate sampling, throttling, lifecycle events, and upload/recovery instrumentation are later implementation steps. A snapshot's revision remains zero until the atomic progress writer is implemented.

Latest implementation head and CI evidence are tracked in PR #14. Do not treat E10 as complete until all acceptance criteria in this document pass.

## Decision log

- Reuse existing transfer progress columns before proposing migrations.
- Keep download caller-consumption semantics separate from provider read progress.
- Verified bytes/chunks are canonical; attempted/queued bytes are not completed progress.
- Dispatch events after commit; terminal events bypass throttling.
- No mandatory frontend, queue, cache, broadcast, or notification dependency.
