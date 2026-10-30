# 10 — Implementation Roadmap (14 Epics)

Implement sequentially; do not start provider-specific work before core contracts and transfer state are stable.

| Epic | Scope | Deliverables | Depends on |
|---|---|---|---|
| E01 Foundation | Composer, provider, config, CI, conventions | Installable Laravel 13 package | — |
| E02 Contracts & API | DTOs, exceptions, driver contracts, facade | Stable PHP API | E01 |
| E03 Persistence | migrations, models, repositories, state machine | Durable metadata | E01–02 |
| E04 Local Driver | streaming local storage, directories, stat/delete | First usable driver | E02 |
| E05 Basic Upload | upload sessions, validation, finalize | Basic upload flow | E03–04 |
| E06 Chunk Engine | chunk planner, bounded concurrency, chunk records | Chunked transfer | E05 |
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
- Plan deterministic offsets.
- Persist per-chunk states.
- Add bounded worker concurrency.
- Verify chunks before marking complete.

### E07 Resume & Idempotency
- Implement idempotency key uniqueness.
- Implement content fingerprint and security scope.
- Reconcile completed chunks.
- Test restart and duplicate request scenarios.

### E08 Recovery & Retry
- Classify transient/permanent errors.
- Add exponential backoff with jitter.
- Add transfer/chunk locks.
- Add stale session reconciliation and cleanup commands.

### E09 Download Engine
- Stream content.
- Implement range support where driver advertises it.
- Verify chunks and final checksum.
- Clean temp resources on all exits.

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

Do not call E01/E02 a production engine. A first usable local-storage release requires at least E01–E05. A resumable/recoverable release requires E01–E10. Production readiness requires all applicable release gates and real deployment validation. E04 is complete only after the current CI workflow passes, all required code and security review findings are addressed, all review threads are resolved, and the work is merged into `main`.


---
