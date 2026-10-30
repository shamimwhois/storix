# 13 — Acceptance and Production Readiness Checklist

## Package
- [ ] Composer package installs in a clean Laravel 13 application.
- [ ] PHP 8.5+ requirement is enforced.
- [ ] Service provider auto-discovery works.
- [ ] Config and migrations publish/run/rollback cleanly.
- [ ] No mandatory frontend dependency exists.

## Storage
- [ ] Local driver passes contract tests.
- [ ] File and directory operations enforce ownership.
- [ ] Storage keys are opaque and path-safe.
- [ ] Streaming works for large files without whole-file memory loading.

## Upload
- [ ] Small and large upload succeed.
- [ ] Chunk offsets and sizes are correct at boundary sizes.
- [ ] Chunk checksums are verified.
- [ ] Duplicate/idempotent request behavior is correct.
- [ ] Resume works after browser disconnect, worker restart and server restart where supported.
- [ ] Only missing/invalid chunks are retransmitted.

## Download
- [ ] Downloaded bytes match original SHA-256.
- [ ] Range/resume behavior matches driver capability.
- [ ] Missing/corrupt chunks are detected and recovered or reported.
- [ ] Temporary resources are cleaned after success and failure.

## Reliability
- [ ] Timeout, connection reset, 429, 5xx and disk-full scenarios tested.
- [ ] Retry is bounded and uses backoff/jitter.
- [ ] Permanent failures are not retried endlessly.
- [ ] Stale sessions can be reconciled.
- [ ] Completed state requires final integrity verification.

## Security
- [ ] IDOR and tenant isolation tests pass.
- [ ] Path traversal tests pass.
- [ ] Secrets are redacted.
- [ ] Rate limits and quotas work.
- [ ] Share tokens, if shipped, are hashed, expiring and revocable.

## Quality
- [ ] PHPUnit passes.
- [ ] Pint passes.
- [ ] Static analysis passes at agreed level.
- [ ] Composer validate passes.
- [ ] Performance tests document hardware, network, file sizes and concurrency.
- [ ] Documentation and changelog match implementation.

A checked box requires test or operational evidence. Do not mark a capability complete based on design alone.


---
