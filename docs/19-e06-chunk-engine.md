# E06 — Chunk Engine

**Status:** Implementation in progress on feature/e06-chunk-engine  
**Depends on:** E01–E05  
**Target:** PHP 8.5+, Laravel 13.x  
**Package:** shamimstack/storix

## 1. Objective

Extend the verified E05 stream-upload workflow with deterministic chunk planning, durable per-chunk records, an optional provider-neutral multipart contract, and verified final-object publication. Preserve the existing PHP upload entry point.

E06 is not a resumable or retrying transfer engine. It adds chunk boundaries and verified multipart assembly. E07 owns resume/session reconciliation; E08 owns retries and background recovery.

## 2. Driver contract

Drivers that support multipart writes implement MultipartStorageDriver in addition to StorageDriver and advertise DriverCapabilities.supportsMultipartWrites = true.

The contract contains four operations:

1. beginMultipartWrite(BeginMultipartWriteRequest): string
2. writeMultipartPart(WriteMultipartPartRequest): MultipartPartResult
3. completeMultipartWrite(CompleteMultipartWriteRequest): WriteResult
4. abortMultipartWrite(string uploadId): void

The upload identifier is opaque to the package. Part results contain a 1-based part number, byte size, SHA-256 digest and optional opaque driver reference. Completion requests contain an ordered list of part results and the expected final size and SHA-256.

A capability mismatch is an error: a driver must not advertise multipart support without implementing the multipart contract. Drivers without multipart support continue through the E05 single-object writeStream() path.

## 3. Chunk planning

ChunkPlanner generates zero-based descriptors with deterministic index, offsetBytes and sizeBytes values.

- Empty objects produce no chunk records and use the normal empty-stream write path.
- Configured chunk size is clamped between the selected driver's declared minimum and maximum.
- Every chunk except the final chunk uses the selected chunk size; the final chunk may be smaller.
- Plans above 10000 parts are rejected before allocating the chunk descriptor list.
- Chunk indices are zero-based in Storix persistence and converted to 1-based provider part numbers at the driver boundary.

## 4. Persistence lifecycle

For multipart uploads, storix_transfers.total_chunks is populated from the plan and completed_chunks advances only after a part result passes verification.

Each storix_chunks row records:

| Column | Meaning |
|---|---|
| chunk_index | Stable zero-based plan position |
| offset_bytes | Start position in the staged source |
| size_bytes | Expected chunk length |
| sha256 | Verified part digest |
| state | pending, uploading, verified or failed |
| driver_reference | Optional provider-specific part identifier |
| attempts | Number of part write attempts |
| last_error_code | Sanitized stable error code on failure |

A chunk becomes verified only after the driver returns the expected part number, exact byte count and matching SHA-256. The driver verifies part contents again before assembling the final object.

## 5. Bounded execution and memory

E06 stages the complete input using E05's bounded php://temp strategy, then stages each part with the configured spool-memory threshold. Part I/O is sequential in this implementation. The Local driver declares maxConcurrency = 1, so no parallelism is advertised or implied.

The max_parallel_chunks configuration remains a future worker-executor ceiling. E06 does not start queue jobs, depend on a worker being online, or continue after the request process exits. Actual concurrency must be implemented and tested before a driver's effective concurrency is raised above one.

## 6. Local multipart implementation

The Local adapter implements the multipart contract using a private temporary workspace under the operating system temporary directory.

- Session identifiers are 256-bit random hexadecimal tokens.
- Workspace directories are created with owner-only (0700) permissions.
- A private manifest binds the session identifier to the originally requested object key.
- Part files are created exclusively and use restrictive file permissions where supported.
- Completion re-reads each part, verifies its recorded size and SHA-256, assembles a bounded stream, and checks aggregate size and SHA-256.
- The assembled stream is published through the existing same-directory staging and atomic-rename writeStream() implementation.
- Complete and abort remove the temporary workspace; missing abort targets are treated as already cleaned.

The workspace is intentionally ephemeral. It is not a durable resume mechanism and may be lost on server restart or temporary-directory cleanup.

## 7. Failure handling

On a multipart failure:

1. Mark unfinished chunk records failed and store only a stable sanitized error code; verified parts retain their verified state.
2. Attempt abortMultipartWrite(); log a sanitized warning if abort fails.
3. Let the E05 outer failure path delete any object that may have been published.
4. Mark the file and non-terminal transfer failed.
5. Close every package-owned staging stream while preserving caller ownership of the original source stream.

A failed finalization never marks the file available.

## 8. Acceptance criteria

- [ ] Existing E05 uploads continue to work for drivers without multipart support.
- [ ] Capability declarations are checked against the optional multipart contract.
- [ ] Chunk offsets and sizes are deterministic and obey driver limits.
- [ ] Empty uploads bypass multipart and produce zero chunk records.
- [ ] Chunk records are created before part I/O and persist verified hashes and state.
- [ ] Provider part number, size and SHA-256 are checked before incrementing progress.
- [ ] Final aggregate size and SHA-256 are checked by the Local driver before publication.
- [ ] Abort and failure cleanup are idempotent and do not expose partial objects.
- [ ] Local workspaces are private, random, isolated and cleaned after completion/abort.
- [ ] Composer validation, Pint and PHPUnit pass on the PR head.

## 9. Explicitly deferred

- Parallel worker scheduling and queue continuation after the request exits.
- Resume, pause, session reconciliation and cross-process durable provider sessions.
- Retry/backoff policy and stale workspace cleanup.
- Download chunk engine and range reconstruction.
- Remote multipart adapters (S3-compatible, Telegram or other providers).
