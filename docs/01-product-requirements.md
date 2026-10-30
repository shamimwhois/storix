# 01 — Product Requirements Document

## 1. Product summary

Storix is a reusable Laravel package that provides a consistent storage and transfer layer. Laravel applications interact with a single API while Storix routes operations to configured storage drivers. The package manages metadata, directories, upload sessions, chunk state, transfer progress, retries, integrity checks, and secure access.

## 2. Goals

1. Offer a simple, stable PHP API for file and directory operations.
2. Support multiple storage drivers behind contracts.
3. Transfer large files using configurable chunking and bounded concurrency.
4. Resume interrupted uploads without retransmitting verified chunks.
5. Recover safely from network, worker, process and storage failures.
6. Provide download streaming, integrity validation and resumability where supported.
7. Expose transfer lifecycle events and optional REST endpoints.
8. Remain independent of frontend frameworks.
9. Enforce tenant/user ownership and prevent unauthorized file access.
10. Be testable and maintainable as a Composer package.

## 3. Non-goals

- A bundled web dashboard or JavaScript frontend.
- A replacement for Laravel's entire filesystem abstraction.
- Running arbitrary code on Telegram Serverless (explicitly excluded).
- Guaranteeing unlimited transfer speed; throughput is bounded by network, provider, hardware, API quotas and configured concurrency.
- Claiming exactly-once network delivery. The engine instead uses idempotent operations, durable state and integrity checks.
- Sharing a single upload session across unrelated users or tenants.

## 4. Target users

- Laravel developers integrating file storage into applications.
- SaaS operators managing user- or tenant-owned files.
- Backend teams needing resumable transfer and provider abstraction.
- Applications that need optional API endpoints and transfer events.

## 5. Core user journeys

### Upload
1. Application creates an upload request with file name, size, MIME type, target directory and optional checksum.
2. Storix authorizes the operation and creates or reuses an eligible upload session.
3. Client/server sends chunks; each chunk is validated and persisted.
4. Storix records chunk state and emits progress events.
5. Missing chunks are retried; completed chunks are verified.
6. Storix assembles or finalizes the object according to driver capabilities.
7. Final checksum is verified and the file becomes `completed`.

### Resume after failure
1. A retry request supplies the upload session ID or a matching idempotency key/fingerprint.
2. Storix verifies owner, tenant, target and session expiry.
3. Storix reconciles durable chunk records with actual driver state.
4. Only missing or invalid chunks are transferred again.
5. The session completes only after final integrity validation.

### Download
1. Application requests a file by opaque ID.
2. Storix authorizes access and confirms the object is available.
3. Driver streams the content; progress is emitted where measurable.
4. If chunked reconstruction is required, chunks are fetched, verified and assembled/streamed.
5. The final checksum is verified when full-object verification is possible.

### Directory operations
Create, list, rename and delete directories subject to ownership, path normalization and non-empty deletion rules.

## 6. Functional requirements

| ID | Requirement | Priority |
|---|---|---|
| FR-001 | Register and resolve storage drivers by name | MUST |
| FR-002 | Upload a file through the PHP API | MUST |
| FR-003 | Download/stream a stored file | MUST |
| FR-004 | Create, list, rename and delete directories | MUST |
| FR-005 | Persist file and transfer metadata | MUST |
| FR-006 | Chunk large uploads with configurable chunk size | MUST |
| FR-007 | Resume interrupted upload sessions | MUST |
| FR-008 | Detect duplicate upload requests safely | MUST |
| FR-009 | Retry transient failures with bounded exponential backoff | MUST |
| FR-010 | Verify chunk and final object integrity using SHA-256 | MUST |
| FR-011 | Expose transfer state and progress | MUST |
| FR-012 | Pause, resume and cancel queued/in-progress transfers where supported | SHOULD |
| FR-013 | Emit Laravel events for lifecycle changes | MUST |
| FR-014 | Provide optional versioned REST API routes | SHOULD |
| FR-015 | Provide optional Redis-backed queue execution | SHOULD |
| FR-016 | Support Telegram storage driver after policy/limit validation | SHOULD |
| FR-017 | Support encryption at rest as an opt-in capability | SHOULD |
| FR-018 | Support deduplication without cross-tenant data leakage | SHOULD |
| FR-019 | Provide retention and cleanup commands | SHOULD |
| FR-020 | Provide structured logs and operational metrics | MUST |

## 7. Non-functional requirements

- **Reliability:** durable state transitions; safe retries; crash recovery.
- **Integrity:** SHA-256 checks for chunks and complete objects.
- **Security:** deny-by-default authorization, tenant isolation, path safety, secret protection.
- **Performance:** streaming I/O, bounded parallelism, connection reuse when supported, configurable chunk size.
- **Scalability:** queue-based processing; no reliance on a single web request for long transfers.
- **Compatibility:** PHP 8.5+ and Laravel 13.x.
- **Maintainability:** contracts, typed DTOs, explicit exceptions, static analysis and automated tests.
- **Observability:** progress, attempt counts, failure codes, timestamps and structured logs.

## 8. Transfer states

`pending → preparing → transferring → verifying → completed`

Failure paths: `transferring → retry_wait → transferring`; terminal states: `failed`, `cancelled`, `expired`. Pause state: `paused` when the selected execution mode supports safe pausing. State transitions must be validated centrally.

## 9. Progress model

Expose at minimum:
- bytes total, bytes transferred and bytes remaining;
- percentage (0–100, never above 100);
- current and average bytes/second;
- estimated remaining seconds (nullable until enough samples exist);
- completed/total chunks;
- current state, attempt count and last error code.

Progress is an estimate. Do not promise an ETA before enough throughput samples exist. Persist progress at a configurable interval to avoid excessive database writes.

## 10. Acceptance criteria

- A file can be uploaded and downloaded with identical SHA-256.
- An interrupted upload can resume after worker/process restart.
- Repeating an eligible upload request does not retransmit verified chunks.
- An unauthorized user cannot inspect, resume, download or delete another user's file/session.
- Transient errors retry within configured limits; permanent errors fail without infinite retry.
- Rate-limit responses honor provider retry guidance.
- Directory traversal and unsafe paths are rejected.
- API and PHP usage work without installing a frontend package.
- All supported drivers pass the same driver contract suite.


---
