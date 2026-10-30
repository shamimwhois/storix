# E04 — Local Storage Driver

**Status:** Implementation in progress on feature/e04-local-driver; CI passing, code review and merge pending  
**Dependencies:** E02 Contracts & API  
**Target:** PHP 8.5+ and Laravel 13.x  
**Package:** shamimstack/storix  
**Namespace:** ShamimStack\\Storix

## 1. Objective

Deliver the first concrete Storix backend: a local filesystem driver that implements the provider-neutral StorageDriver contract. It must stream bytes with bounded PHP memory, protect the configured storage root from unsafe keys, and provide object metadata and basic directory operations.

E04 makes storage-driver operations usable. It does not yet implement an upload workflow, database-backed file orchestration, chunk planning, resumability, retry workers, or downloads through transfer records.

## 2. Public contracts

### 2.1 StorageDriver

LocalStorageDriver implements the existing E02 methods:

| Method | Behavior |
|---|---|
| writeStream(WriteStreamRequest) | Stream to a same-directory temporary file, flush, then rename into place |
| readStream(ReadStreamRequest) | Return a readable stream; optional offset/length range is supported |
| delete(DeleteObjectRequest) | Delete a file; deleting a missing object is idempotent |
| exists(string $key) | Return whether a regular object exists |
| stat(StatObjectRequest) | Return provider-neutral metadata or null when absent |

The driver reports streaming, range-read, and atomic-finalize capabilities. It does not claim multipart writes or pause/resume. A null maximum object size means no package-level limit is advertised; it is not a guarantee of unlimited file size.

### 2.2 Optional DirectoryDriver

The E02 object contract remains unchanged. Drivers that expose directory operations may additionally implement DirectoryDriver:

- makeDirectory(string $key)
- listDirectory(string $key = '') — immediate children only; an empty key denotes the configured root.
- deleteDirectory(string $key, bool $recursive = false)

Directory listing returns typed StorageDirectoryEntry values. The local storage root cannot be deleted. Recursive deletion is opt-in; a non-empty directory cannot be removed through the default non-recursive operation.

Example:

```php
use ShamimStack\\Storix\\Contracts\\DirectoryDriver;
use ShamimStack\\Storix\\Facades\\Storix;

$driver = Storix::driver();

if (! $driver instanceof DirectoryDriver) {
    throw new RuntimeException('The selected driver does not expose directory operations.');
}

$driver->makeDirectory('documents/2026');
$entries = $driver->listDirectory('documents');
```

## 3. Configuration and registration

The configured local root is read from storix.drivers.local.root:

```php
// config/storix.php
'drivers' => [
    'local' => [
        'root' => storage_path('app/storix'),
    ],
],
```

The package service provider registers a singleton LocalStorageDriver and registers it in DriverManager under the stable name local. The existing default-driver setting continues to choose which registered driver resolves when no name is supplied.

Applications may still register custom drivers through DriverManager; Storix does not silently fall back when a requested driver is unavailable.

## 4. Storage-key security

A storage key is a relative, opaque POSIX-style key, not an operating-system path or a public URL.

The local driver rejects:

- absolute paths and leading/trailing separators;
- empty segments, . and .. traversal segments;
- backslashes, null bytes, control characters, and platform-reserved filename characters;
- Windows device names (including names with extensions), segments ending in a dot or space, and the reserved `.storix-tmp-` internal namespace.

The driver builds paths only below the configured root, checks existing path components for symbolic links, does not follow symlinks during directory listing or recursive deletion, reserves the `.storix-tmp-` namespace for private temporary files, and refuses to delete the root directory. Error messages use stable package exceptions and do not include absolute filesystem paths.

These checks mitigate traversal and static symlink escapes. They are not a promise of race-free protection against a hostile local process concurrently replacing filesystem entries between checks and I/O. The configured root must be writable only by trusted application processes.

## 5. Streaming and finalization

### Writes

- Reads the input resource from its current stream position; it does not rewind or close the caller's input stream.
- Copies in fixed 1 MiB chunks, so file contents are not accumulated in PHP memory.
- Computes SHA-256 while copying.
- Writes to a `tempnam()`-reserved file in the destination directory. The file is created with private permissions (0600 on POSIX systems), and the driver rejects a temp-file fallback outside that directory. The `.storix-tmp-` prefix is reserved for these internal files and cannot be used in user keys.
- Flushes and closes the temporary file before renaming it to the final key.
- Removes the temporary file on failure; an existing destination is not replaced until the new stream has been written successfully.

Finalization uses a same-directory rename. Atomic replacement is available where the underlying local filesystem provides the expected rename semantics.

### Reads

- A full read returns a direct readable file stream positioned at the requested offset.
- A bounded range read copies only the requested bytes into php://temp, configured with a 2 MiB memory threshold; larger ranges spill to the system temporary storage rather than growing PHP memory without bound.
- The caller owns and must close the returned stream.
- The driver never loads the entire object into a PHP string.

### Metadata

A write result includes the byte count, detected or caller-supplied MIME type, SHA-256 checksum, modification time, visibility value, and scalar metadata. A later stat() call does not re-hash the object; its checksum field remains unset unless a future implementation explicitly verifies it.

The local driver's visibility field is metadata only. It does not expose objects through a web server or change operating-system access controls.

## 6. Failure semantics

- Missing exists() returns false.
- Missing stat() returns null.
- Deleting a missing object or directory is a no-op.
- Read/write/stat/list/delete failures use StorageOperationFailedException with STORAGE_OPERATION_FAILED.
- Invalid keys fail validation with InvalidArgumentException.
- Non-recursive deletion of a non-empty directory fails.
- Symlinks are not followed. Recursive directory deletion aborts if it encounters a symlink.

The driver does not expose raw local paths in its public exception messages.

## 7. Tests

The E04 feature suite must cover:

- provider registration and default driver resolution;
- binary stream writes and SHA-256 correctness;
- nested keys and overwrite/finalize behavior;
- cleanup of temporary files;
- full reads, offset reads, and bounded range reads;
- metadata, existence, delete, and missing-object behavior;
- directory creation, immediate listing, non-recursive delete, and explicit recursive delete;
- traversal, absolute, malformed, and platform-reserved key rejection;
- symlink rejection without following links;
- stable error codes and safe error messages;
- advertised driver capabilities.

CI currently targets PHP 8.5 and Laravel 13 with Composer validation, Pint, and PHPUnit. E04 does not claim MySQL/PostgreSQL coverage beyond the separately documented E03 database matrix.

## 8. Out of scope

- Upload sessions and storix_files / storix_transfers orchestration (E05).
- Chunk planning, parallel chunk writes, and persisted chunk transmission (E06).
- Resume and fingerprint reconciliation (E07).
- Retry workers, locks, and stale-session recovery (E08).
- Transfer download orchestration and end-to-end integrity (E09).
- Telegram, S3-compatible, R2, REST, UI, and serverless integrations.
- A guaranteed 50–100 GB maximum, unlimited size, or guaranteed throughput.

Actual size and throughput depend on the host filesystem, available disk space, quotas, PHP stream behavior, and operating-system limits. Large-file readiness must be established with representative integration and performance tests rather than inferred from the streaming implementation alone.

## 9. Acceptance criteria

- [x] Local driver implements the E02 storage contract.
- [x] Local driver is registered by the Laravel service provider.
- [x] Optional directory operations have a typed contract and result DTO.
- [x] Streamed writes use bounded chunks and calculate SHA-256.
- [x] Writes finalize through a same-directory temporary file and rename.
- [x] Full and bounded range reads return caller-owned streams.
- [x] Key validation rejects traversal and absolute paths.
- [x] Existing symlink components are rejected and are not traversed in listing/deletion.
- [x] Object metadata, existence, and deletion behavior are implemented.
- [x] E04 feature tests pass in CI (48 tests, 162 assertions).
- [ ] Review findings are addressed and all review threads are resolved.
- [ ] E04 is merged into main.
