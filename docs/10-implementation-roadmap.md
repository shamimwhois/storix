# 10 — Implementation Roadmap (14 Epics)

Implement sequentially; do not start provider-specific work before core contracts and transfer state are stable.

| Epic | Scope | Deliverables | Depends on |
|---|---|---|---|
| E01 Foundation | Composer, provider, config, CI, conventions | Installable Laravel 13 package | — |
| E02 Contracts & API | DTOs, exceptions, driver contracts, facade | Stable PHP API | E01 |
| E03 Persistence | migrations, models, repositories, state machine | Durable metadata | E01–02 |
| E04 Local Driver | streaming local storage, directories, stat/delete | First usable driver | E02 |
| E05 Basic Upload | upload sessions, validation, finalize | Basic upload flow | E03–04 |
| E06 Chunk Engine | deterministic planner, multipart driver contract, verified chunk records and bounded execution | Chunked transfer | E05 |
| E07 Resume & Idempotency | fingerprints, idempotency, session reconciliation | Resume without duplicate work | E06 |
| E08 Recovery & Retry | error classification, backoff, locks, cleanup | Fault recovery | E06–07 |
| E09 Download Engine | stream, range/chunk retrieval, integrity | Reliable downloads | E04, E03 |
| E10 Progress & Events | metrics, ETA, events, notifications hooks | Transfer observability | E05–09 |
| E11 Performance | benchmarks, tuning, quotas, load tests | Measured throughput | E06–10 |
| E12 Telegram Driver | official/local Bot API adapter after verification | Optional Telegram backend | E02–11 |
| E13 REST Adapter & Security | optional routes, auth, policies, rate limits, sharing | API integration | E03–10 |
| E14 Release & Operations | docs, CI matrix, compatibility, upgrade guide | Release candidate | E01–13 |

## Epic task detail

### E01 Foundation
- Set package metadata and PSR-4 namespace.
- Register service provider and publish config/migrations.
- Add CI for PHP 8.5 and Laravel 13.
- Add package installation smoke test.

### E02 Contracts & API
- Define typed DTOs and enums.
- Define driver capability model.
- Define stable exceptions and error codes.
- Implement facade and service bindings.

### E03 Persistence
- Implement the four core migrations: directories, files, transfers, and chunks.
- Implement UUID-backed Eloquent models and relationships.
- Define repository contracts and Eloquent implementations with SQL-level tenant/owner scoping.
- Implement the transfer state machine and atomic idempotency constraints.
- Test indexes, uniqueness, rollback, and supported database behavior.
- Follow [E03 Persistence specification](16-e03-persistence.md).

### E04 Local Driver
- Implement LocalStorageDriver against the E02 provider-neutral contract.
- Pass reusable StorageDriver contract tests against the local adapter.
- Register the Local driver through the Laravel service provider and configured root path.
- Stream writes in bounded chunks, calculate SHA-256, and finalize through a same-directory temporary file and rename.
- Stream full reads and bounded ranges; implement stat, exists, delete, and stable safe errors.
- Add optional DirectoryDriver operations for create, immediate listing, and safe deletion.
- Reject traversal/absolute keys and refuse to follow symbolic links.
- Test binary payloads, range reads, finalization, directories, symlinks, and error behavior.
- Follow [E04 Local Storage Driver specification](17-e04-local-driver.md).

### E05 Basic Upload
- Add typed UploadRequest/UploadResult and expose UploadManager through the facade.
- Stage readable streams with bounded memory; enforce a configurable 64 MiB initial single-upload limit.
- Validate names, MIME metadata, expected size, directory scope and driver capabilities.
- Create scoped file/transfer records and enforce idempotency replay/conflict semantics.
- Write one object through StorageDriver; independently verify stat, size and SHA-256 by streamed read-back.
- Finalize file availability and transfer completion atomically; compensate with safe deletion on failure.
- Add binary/empty-file, validation, idempotency, integrity and cleanup tests.
- Follow [E05 Basic Upload specification](18-e05-basic-upload.md).

### E06 Chunk Engine
- Add deterministic chunk planning with driver min/max size limits and a 10000-part ceiling.
- Add an optional multipart driver contract with begin, write-part, complete and abort operations.
- Persist chunk offsets, sizes, attempts, checksums, provider references and lifecycle states.
- Stage each part with bounded memory, verify part number/size/SHA-256, and only finalize after all parts pass.
- Compensate failures by aborting the multipart workspace, deleting any possibly published object, and marking unfinished chunks/file/transfer failed.
- Keep the Local adapter sequential with maxConcurrency = 1. Queued parallel workers, resume and retry remain E07–E08 work.

### E07 Resume & Idempotency
- Persist opaque multipart session identifiers and chunk verification timestamps.
- Bind canonical request fingerprints to tenant/owner security scope and all upload attributes.
- Acquire idempotency records atomically; retain the database unique constraint as the concurrency invariant.
- Reconcile verified chunks against deterministic offsets, sizes, staged-byte digests and provider session identity.
- Resume only matching sessions; fail closed on changed payloads, scope, chunk layout or provider references.
- Recompute progress from verified chunks rather than incrementing counters during recovery.
- Test restart, duplicate request, concurrency, changed payload and stale-session scenarios.
- Follow [E07 Resume & Idempotency specification](20-e07-resume-idempotency.md).

### E08 Recovery & Retry
- Classify transient, rate-limited, permanent, and unknown failures without exposing provider details.
- Persist bounded exponential backoff deadlines with jitter and enforce them before an idempotent resume.
- Reuse token-owned transfer leases and periodic heartbeats during recovery.
- Recover a provider-published final object only after independent size and SHA-256 verification.
- Reconcile stale transfers and clean abandoned or terminal multipart workspaces through a bounded, dry-run-capable Artisan command.
- Preserve available file objects when cleaning terminal transfer sessions.
- Follow [E08 Recovery & Retry specification](21-e08-recovery-and-retry.md).

### E09 Download Engine
- Add typed DownloadRequest/DownloadResult and expose DownloadManager through the Storix facade.
- Resolve files only through tenant/owner-scoped repository lookup and allow only available records.
- Derive the storage driver and opaque key from persisted file metadata; never accept a caller-supplied storage key.
- Require advertised streaming capability and native range-read capability for range requests.
- Verify provider size metadata, stream byte count, and full-object SHA-256 before returning content.
- Verify native range bytes against the corresponding slice of the verified full-object snapshot.
- Bound in-memory spooling and close all intermediate resources on every exit path.
- Test full downloads, range boundaries, capability rejection, scope isolation, unavailable files, tampering, and stream cleanup.
- Follow [E09 Download Engine specification](24-e09-download-engine.md).

### E10 Progress & Events
- Expose bytes, percent, speed, ETA and chunk counts.
- Add lifecycle events.
- Throttle persistence/broadcast updates.
- Provide notification hooks without requiring a frontend.

### E11 Performance
- Benchmark chunk sizes and concurrency.
- Benchmark local key case/normalization collision checks and directory operations at high directory cardinality.
- Enforce memory and disk budgets.
- Add provider/tenant concurrency controls.
- Document measured results and test environment.

### E12 Telegram Driver
- Re-check official policy and API limits.
- Implement credentials safely.
- Map provider capabilities and errors.
- Run opt-in integration tests.

### E13 REST Adapter & Security
- Add optional versioned routes.
- Add request validation/resources.
- Add policies, tenant scoping and rate limits.
- Add secure share links only if included in release scope.

### E14 Release & Operations
- Complete docs and examples.
- Verify clean installation and upgrade path.
- Run security/performance suite.
- Tag version only after all release gates pass.

## Milestone policy

Verified GitHub state on 2026-11-01:

| Epic | Repository state | PR | Completion interpretation |
|---|---|---|---|
| E01–E04 | Merged into `main` | #1–#4 closed/merged | Complete |
| E05 Basic Upload | Merged into `main` | #5 closed/merged | Complete |
| E06 Chunk Engine | `feature/e06-chunk-engine` | #6 open, non-draft, base `main` | Implemented for review; not merged |
| E07 Resume & Idempotency | `feature/e07-resume-idempotency` | #7 open draft, base E06 | Implemented for review; not merged |
| E08 Recovery & Retry | `feature/e08-recovery-retry` | #8 open, non-draft, base E07 | Final-head CI and review gates verified; not merged |
| E09 Download Engine | `feature/e09-download-engine` | #13 open, non-draft, base E08 | Implementation, 4-way CI, and code/security review verified; not merged |
| E10 Progress & Events | `feature/e10-progress-events` | #14 open draft, base E09 | M01 scoped progress snapshot API implemented; latest-head CI pending |

Do not merge E07 or E08 directly into `main` ahead of their base dependencies. E06 must merge first, then E07 must be rebased/validated against `main`, followed by E08. Keep each PR's implementation evidence distinct; a green run on an earlier head does not verify later commits.

E08 includes failure classification, persisted backoff deadlines, retry-deadline enforcement, token-owned recovery leases and heartbeats, provider-published object recovery after independent size/SHA-256 verification, stale-session expiry, and terminal multipart cleanup. The Artisan command supports bounded candidate counts and aggregate-only dry-run. A recovery pass cannot recreate missing source bytes; callers must resubmit the original source stream after the persisted retry deadline. E08 final-head Composer, Pint, PHPUnit, and SQLite/MySQL 8.4/PostgreSQL 17 CI gates passed on commit c788d86d25f489512f4977f3a4589dd86873f76a, including migration rollback/reapply coverage; no unresolved inline review threads remained at the verification check. The milestone is implementation-verified but remains unmerged because E06 and E07 are stacked dependencies. A separate security hardening item remains: strict provider-level no-overlap fencing is not provided by database leases and is tracked in issue #11.

The E07 CI run recorded 102 tests / 424 assertions on SQLite, MySQL 8.4, and PostgreSQL 17. E08's final-head green run is recorded separately above; the E07 run is not used as evidence for E08. Driver-call heartbeat during a single blocking provider operation, queue-based source re-submission, and remote-provider recovery semantics remain follow-up work.


Do not call E01/E02 a production engine. A first usable local-storage release requires at least E01–E05. A resumable/recoverable release requires E01–E10. Production readiness requires all applicable release gates and real deployment validation. E04 is complete only after the current CI workflow passes, all required code and security review findings are addressed, all review threads are resolved, and the work is merged into `main`.


---


E08 final-head validation on commit c788d86d25f489512f4977f3a4589dd86873f76a passed the PHP 8.5 / Laravel 13 SQLite, MySQL 8.4, and PostgreSQL 17 jobs. No unresolved inline review threads remained at the verification check. E08 is still stacked on E07 and is not merged. Database lease ownership is not provider-level fencing: an accepted in-flight write/abort/delete may finish after lease expiry. The fail-closed cleanup_pending behaviour remains intentional; strict no-overlap storage fencing is tracked separately in issue #11 and must not be represented as an E08 guarantee.

E09 implementation is on `feature/e09-download-engine`, based on `feature/e08-recovery-retry`. PR #13 is open, non-draft, and unmerged. Current head `da069a864cf7610c149b5192be172e0527747b5c` passed four-way CI run [#41278638231](https://github.com/shamimwhois/storix/actions/runs/41278638231) on PHP 8.5 / Laravel 13: SQLite, MySQL 8.4, PostgreSQL 17, and Windows SQLite. Linux Pint passed across 94 PHP files. The 159-test suite passed in every job (689 assertions on the three database jobs; 683 on Windows). Nine PHPUnit notices remain per job, with 1 skipped test on each database job and 4 on Windows. All four inline review threads are resolved; a dedicated Codex security review and general code review on the current head reported no major issues. The Windows lock-directory trust opt-in remains an explicit operator ACL/reparse-point trust boundary, documented in `docs/24-e09-download-engine.md`. E09 is implementation-verified and review-ready but remains unmerged because E06–E08 are stacked dependencies.

E10 is the next planned epic. Do not implement it as a frontend-specific feature: progress snapshots, event contracts, notification hooks, persistence throttling, and queue/worker-safe dispatch must remain framework/package-level contracts. Begin E10 planning against the E05–E09 APIs without changing or merging the stacked E06–E09 branches until their dependency sequence is explicitly handled.
