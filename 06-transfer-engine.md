# 06 — Upload, Download and Transfer Engine

## 1. Upload protocol

1. Validate user authorization, source metadata, configured maximum size and available temp disk.
2. Create or reuse an idempotent upload session.
3. Determine chunk size from configured policy and driver capabilities.
4. Create deterministic chunk records with offsets and expected sizes.
5. Transfer chunks with bounded concurrency.
6. Verify each chunk using SHA-256 when bytes are available.
7. Persist provider references and chunk completion atomically.
8. Reconcile missing chunks and retry transient failures.
9. Finalize provider object.
10. Verify final object checksum; mark file and transfer completed.

## 2. Resumability

A session is resumable only when:
- the owner and tenant match;
- the session has not expired or been cancelled;
- the target driver and target object remain compatible;
- the source fingerprint matches, or the client supplies the exact session and remaining chunks are independently validated;
- completed chunks still exist and pass integrity checks.

A new request with the same name is not proof of the same file. Prefer a full SHA-256 fingerprint; otherwise use a provisional session and verify content as it arrives.

## 3. Retry policy

Classify errors:
- **Transient:** timeout, connection reset, temporary DNS failure, provider 5xx, temporary unavailable.
- **Rate limited:** honor `Retry-After` or provider-specific retry metadata.
- **Permanent:** invalid credentials, invalid path, unsupported file, authorization failure, invalid checksum, unsupported operation.
- **Unknown:** bounded retry, then fail and surface sanitized diagnostic details.

Use exponential backoff with jitter, a maximum delay and maximum attempts. Avoid retry storms. A retry must be idempotent.

## 4. Download protocol

- Prefer driver streaming or range reads.
- If reconstruction is needed, fetch chunks to a controlled temporary stream/file, verify each chunk, then stream/assemble.
- Resume download only when the driver supports range reads or the package can safely map byte ranges to chunks.
- Send correct content length and safe content-disposition headers through the optional HTTP adapter.
- Clean temporary files in `finally` blocks and scheduled cleanup jobs.
- Verify full-object SHA-256 before claiming verified completion; for streamed delivery, document whether verification occurs before or during delivery.

## 5. Progress and ETA

Progress formula: `transferred_bytes / total_bytes * 100`. Guard against zero total size and clamp to 0–100. Speed is measured over a rolling time window. ETA is `remaining_bytes / measured_bytes_per_second`, nullable when speed is zero or insufficient samples exist. Chunk progress and byte progress may differ; expose both.

## 6. Concurrency and speed

Use configurable `max_concurrency`, per-driver limits and per-tenant limits. Start conservatively; benchmark on realistic hardware and network. Increase concurrency only while throughput improves and error/rate-limit rates remain acceptable. Use streaming I/O and avoid buffering full files in memory. Do not claim “no upload/download failure” or guaranteed maximum speed; design for recoverability and measurable performance.

## 7. Duplicate detection and deduplication

- Scope all deduplication to the same tenant/security boundary by default.
- Use `(scope, sha256, size)` as a candidate key after full hash is known.
- Verify existing content before reuse.
- Never reveal to a user that another tenant has an identical file.
- Chunk-level deduplication is an optimization, not a correctness dependency.
- Reference-count shared blobs if physical storage is shared; deletion must not remove bytes still referenced.

## 8. Pause, cancel and cleanup

Pause prevents new chunks from starting and records state; in-flight operations must finish or be safely abandoned. Cancel is a terminal user intent and cleanup is asynchronous. Expired sessions are cleaned by scheduled jobs after a configurable retention window. Keep audit metadata according to host retention policy.


---
