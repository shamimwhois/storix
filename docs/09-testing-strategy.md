# 09 — Testing Strategy

## 1. Test pyramid

### Unit
- chunk offset/size planning;
- state machine transition rules;
- retry classification/backoff;
- progress, speed and ETA calculations;
- fingerprint generation;
- path normalization;
- checksum verification.

### Feature
- service provider registration and config;
- migrations and model relationships;
- PHP API upload/download/delete;
- directory operations;
- transfer event dispatch;
- optional REST validation and authorization.

### Driver contract
Run the same suite against every driver:
- put/get stream round-trip;
- exists/stat/delete;
- large file behavior within declared limits;
- range/multipart behavior when advertised;
- error classification and cleanup.

### Integration
- real queue worker;
- database transaction and after-commit behavior;
- Redis queue/cache if supported;
- local filesystem;
- Telegram sandbox integration only when credentials/environment are configured.

### Security
- cross-user and cross-tenant IDOR;
- path traversal and malformed paths;
- MIME spoofing and oversized uploads;
- share token brute force/revocation/expiry;
- secret leakage in logs/errors;
- replay/idempotency conflicts;
- rate-limit and resource exhaustion.

### Performance and resilience
- multiple file sizes and concurrency levels;
- memory usage remains bounded;
- worker killed mid-transfer;
- network timeout/reset;
- provider 429/5xx;
- disk full and permission denied;
- corrupt/missing chunk;
- database/queue interruption;
- retry exhaustion and recovery.

## 2. Required test fixtures

Keep small deterministic fixtures in `tests/Fixtures/`:
- `sample-upload.txt`
- `sample-download.txt`
- `sample-binary.bin`
- generated large-file fixture created during tests (do not commit huge binaries).

Verify expected SHA-256 values in tests. Fixtures must contain non-sensitive synthetic data.

## 3. CI gates

- PHPUnit test suite;
- Laravel Pint;
- PHPStan or Larastan at agreed strictness;
- Composer validation;
- dependency audit where available;
- migration tests;
- coverage threshold for critical transfer/security code;
- package installation test in a clean Laravel 13 application.

## 4. Definition of done

A feature is done only when implementation, tests, docs, failure behavior, authorization, observability and compatibility checks are complete. No test may depend on live Telegram credentials in the default CI suite.


---


## E06 chunk and multipart coverage

- Deterministic chunk offsets, configured-size clamping, empty transfers and the 10000-part ceiling.
- Multipart DTO ordering, part-number constraints and checksum-shape validation.
- Per-part checksum verification and final aggregate size/hash verification.
- Local multipart finalization, caller-owned stream lifetime, session-to-key binding and abort idempotency.
- Upload-level persistence of chunk offsets, sizes, attempts, checksums and VERIFIED state.
- Failure-path assertions ensure failed file/transfer state is persisted after provider failures.
