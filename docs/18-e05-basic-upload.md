# E05 — Basic Upload

**Status:** Implementation complete on branch; CI verification and review pending  
**Branch:** `feature/e05-basic-upload`  
**Depends on:** E01 Foundation, E02 Contracts & API, E03 Persistence, E04 Local Driver  
**Target:** PHP 8.5+, Laravel 13.x  
**Package:** `shamimstack/storix`

## 1. Objective

Implement a reliable, synchronous, single-object upload workflow on top of the existing driver contract, persistence repositories, transfer state machine, and Local storage adapter.

E05 is the first end-to-end upload path. It accepts a readable PHP stream, validates and stages the source with bounded memory, creates scoped file/transfer metadata, writes through the selected storage driver, independently verifies the stored bytes, and only then marks the file available and transfer completed.

## 2. Scope

### Included

- A typed `UploadRequest` and `UploadResult`.
- A Laravel-resolvable `UploadManager`, exposed through `Storix::uploads()`.
- Stream-first upload input; the caller retains ownership of the source stream.
- Filename, MIME metadata, expected-size, identity-scope and directory validation.
- Configurable maximum single-upload size; initial configured default is **64 MiB**, further capped by the selected driver's declared `maxObjectSizeBytes` when present.
- A bounded-memory staging stream using `php://temp`; default in-memory threshold is **8 MiB**, with overflow spooled by PHP to its temporary-file backing store.
- File and transfer metadata creation using the E03 repositories.
- Optional idempotency-key replay protection and conflict handling.
- Single-object storage through E02 `StorageDriver::writeStream()`.
- Independent post-write stat and streamed SHA-256 read-back verification.
- Validated transfer lifecycle transitions and atomic final metadata availability.
- Compensating storage deletion and failed metadata state when write/finalization/verification fails.
- Unit and feature tests for success, validation, ownership, idempotency, integrity and failure cleanup.

### Excluded

- Chunk splitting, multipart/chunk workers, parallel upload, resumable upload and pause/resume workflows (E06–E07).
- Automatic retry, backoff, recovery workers and reconciliation commands (E08).
- Download orchestration (E09).
- REST controllers, browser upload UI, share links and notification delivery (E10/E13).
- Telegram or any additional provider driver (E12).
- Claims of unlimited file size or a guaranteed 50–100 GB upload limit.

## 3. Public API

The primary interface is PHP services, not an HTTP endpoint:

```php
use ShamimStack\Storix\DTOs\UploadRequest;
use ShamimStack\Storix\Facades\Storix;

$stream = fopen($path, 'rb');

try {
    $result = Storix::uploads()->upload(new UploadRequest(
        stream: $stream,
        originalName: 'quarterly-report.pdf',
        tenantId: 'tenant-123',
        ownerId: 'user-456',
        idempotencyKey: 'report-upload-2026-01',
    ));
} finally {
    // The caller owns the stream and closes it.
    if (is_resource($stream)) {
        fclose($stream);
    }
}

$file = $result->file;
$transfer = $result->transfer;
$wasReplay = $result->replayed;
```

### UploadRequest fields

| Field | Required | Meaning |
|---|---:|---|
| `stream` | yes | Readable PHP stream resource, read from its current position |
| `originalName` | yes | Display filename; never used as a physical storage path |
| `tenantId` | no | Host application's tenant identifier, maximum 64 bytes |
| `ownerId` | no | Host application's owner identifier, maximum 64 bytes |
| `directoryId` | no | Existing directory UUID in the same tenant/owner scope |
| `driver` | no | Registered driver name; defaults to configured Storix driver |
| `mimeType` | no | Optional validated MIME type |
| `visibility` | no | Defaults to `private`; E05 currently accepts only `private` |
| `expectedSizeBytes` | no | Optional caller-supplied size, checked against bytes actually read |
| `idempotencyKey` | no | Optional stable key, maximum 128 bytes |
| `metadata` | no | Provider-neutral scalar metadata only |

The input stream is not closed, rewound or otherwise owned by Storix. The caller must position it at the first byte intended for upload and close it after the call returns.

## 4. Configuration

Add the following configuration:

```php
'uploads' => [
    'max_file_size_bytes' => 64 * 1024 * 1024,
    'spool_memory_bytes' => 8 * 1024 * 1024,
],
```

The service reads the live configuration at execution time. The source is copied in bounded 1 MiB reads into `php://temp/maxmemory:<threshold>`; PHP spills excess staged bytes to its temporary-file backing store rather than requiring the entire upload in PHP memory.

The size limit is checked while reading, not trusted from request metadata. Empty files are valid. If `expectedSizeBytes` is supplied, it must match the actual number of bytes read.

## 5. Validation and storage-key rules

- Require a valid readable stream resource.
- Reject blank names, path separators, null bytes, control characters and names that exceed the database column limit.
- Treat the supplied filename as display metadata only.
- Validate optional MIME type length and reject control characters.
- Require tenant/owner IDs to be null or non-empty strings of at most 64 bytes.
- If `directoryId` is supplied, resolve it using tenant/owner-scoped repository lookup and require its driver to match the upload driver.
- Generate an opaque random storage key independently of the user's filename.
- Let the driver enforce its own storage-key safety rules; never concatenate untrusted filename/path input into a storage key.
- Reject uploads larger than the lower of `storix.uploads.max_file_size_bytes` and the selected driver's `maxObjectSizeBytes`, when that driver limit is declared.
- Reject `public` and `limited` visibility until authenticated delivery and sharing policies are implemented; E05 only stores private objects.

## 6. Idempotency

The service stages and hashes the source before claiming a transfer so it can compare actual content rather than trusting a filename or client-provided hash.

The request fingerprint is a SHA-256 digest over canonical upload attributes: content SHA-256, actual byte size, normalized filename, selected driver, target directory, MIME type, visibility and sorted metadata. The repository adds the tenant/owner scope to the idempotency uniqueness key.

- No idempotency key: create a new upload on every call.
- Existing completed key + matching fingerprint + available file: return the prior result with `replayed = true`; do not write the bytes again.
- Existing key + different fingerprint: raise `IdempotencyConflictException`.
- Existing key still active: raise `UploadInProgressException`; do not start a competing write.
- Existing failed/cancelled/expired key: reject reuse; a new request key is required. E08 owns retry/recovery semantics.
- Use the repository's atomic `firstOrCreateIdempotent()` result and `wasRecentlyCreated` to handle concurrent requests. Never rely on a check-then-insert sequence alone.

## 7. Persistence and lifecycle

Create file and transfer records in a short database transaction, then release the transaction before storage I/O.

Transfer states:

```text
pending -> preparing -> transferring -> verifying -> completed
                 \          \              \
                  +----------+---------------> failed
```

File states:

- `pending`: metadata reserved; no completed object is available.
- `processing`: storage write is in progress or awaiting verification.
- `available`: final object exists and read-back integrity has passed.
- `failed`: upload failed; no downloadable object may be exposed.

E05 is a single-object operation, not a chunked transfer. It records byte progress but leaves `total_chunks` and `completed_chunks` at zero; E06 introduces chunk records and chunk counters.

## 8. Write, verify and finalize

1. Resolve the requested/default driver; check streaming support and calculate the effective size cap from package configuration and driver capabilities.
2. Validate directory access and stage the source while computing actual byte count and SHA-256.
3. Claim the scoped idempotency key and create the file/transfer metadata.
4. Transition the transfer through `preparing` to `transferring`.
5. Call `writeStream()` using the opaque key and staged stream.
6. Compare the write result's size and SHA-256 against the staged source.
7. Call `stat()` and read the stored object back as a stream; independently compute its byte count and SHA-256.
8. Require all three observations (source, write result and read-back) to agree.
9. In one database transaction, set file metadata/status to `available` and transition the transfer from `verifying` to `completed`.
10. Return the typed result. Never return a public URL or expose a provider reference as a URL.

Database transactions must not remain open during filesystem or provider I/O.

## 9. Failure handling and cleanup

- On any failure after reserving metadata, attempt idempotent driver deletion of the generated key.
- Mark the file `failed` and transition a non-terminal transfer to `failed` in a database transaction.
- Persist stable error codes and sanitized messages only; never persist raw provider errors, absolute paths or credentials.
- Preserve the original exception as the previous exception for internal diagnostics.
- A cleanup failure must be logged/retained as a sanitized operation failure; it must not cause the file to be marked available.
- Always close Storix-owned staging streams. Never close the caller-owned source stream.
- Do not automatically retry in E05. E08 will own retry classification and worker recovery.

## 10. Acceptance criteria

- [ ] Public `Storix::uploads()->upload(UploadRequest)` resolves from the Laravel container.
- [ ] A binary stream can be uploaded through the configured Local driver.
- [ ] The caller's source resource remains open after the operation.
- [ ] The configured size limit is enforced during streaming; incorrect expected sizes are rejected.
- [ ] Invalid names, MIME metadata, streams and inaccessible directories are rejected before storage writes.
- [ ] File and transfer records carry matching tenant/owner/driver/directory scope.
- [ ] Transfer lifecycle follows the state machine and finishes only after verification.
- [ ] Stored size and SHA-256 match an independent streamed read-back.
- [ ] Idempotent replay returns the existing completed result without rewriting; conflicts and in-progress duplicates are rejected.
- [ ] Write, stat and read-back failures trigger safe cleanup and failed metadata state.
- [ ] PHPUnit covers empty/binary files, size limit, invalid input, directory scope, idempotency, read-back mismatch and cleanup.
- [ ] Pint, Composer validation and the complete PHPUnit suite pass in CI.
- [ ] README, API reference, package structure, changelog and docs portal reflect only implemented behavior.

## 11. Implementation sequence

1. Add `UploadRequest`, `UploadResult` and stable upload exceptions/error codes.
2. Add upload size/spool configuration.
3. Implement source staging, fingerprinting and safe filename validation.
4. Implement idempotency claim/replay handling.
5. Implement metadata lifecycle, driver write, stat/read-back verification and compensating cleanup.
6. Expose UploadManager through the service provider, manager and facade.
7. Add unit/feature/failure-path tests.
8. Update documentation and changelog; keep the documentation commit separate from implementation commits.
9. Run CI, review all findings, then open one E05-level pull request.
