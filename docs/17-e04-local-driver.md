# E04 — Local Storage Driver

**Status:** E04 implementation and final review fixes in progress; merge pending  
**Dependencies:** E02 Contracts & API  
**Target:** PHP 8.5+ and Laravel 13.x  
**Package:** shamimstack/storix  
**Namespace:** ShamimStack\Storix

## 1. Objective

Deliver the first concrete Storix backend: a local filesystem driver implementing the provider-neutral StorageDriver contract. The implementation must stream object bytes with bounded PHP memory, protect the configured storage root from unsafe keys, and expose object metadata and basic directory operations.

E04 provides backend storage primitives. It does not implement upload orchestration, database-backed transfer creation, chunk planning, resumability, retry workers, or transfer download workflows.

## 2. Public contracts

### 2.1 StorageDriver

LocalStorageDriver implements the existing E02 methods:

| Method | Behavior |
|---|---|
| writeStream(WriteStreamRequest) | Stream to a private same-directory staging file, flush, then rename into place |
| readStream(ReadStreamRequest) | Return a readable stream; optionally support offset and bounded length |
| delete(DeleteObjectRequest) | Delete a file; deletion of a missing object is idempotent |
| exists(string key) | Return whether a regular object exists |
| stat(StatObjectRequest) | Return provider-neutral metadata or null when absent |

The driver advertises streaming, range reads, and atomic finalize. It does not advertise multipart writes or pause/resume. A null maximum object size means Storix does not set a package-level limit; it is not a guarantee of unlimited file size.

### 2.2 Optional DirectoryDriver

The E02 object contract remains unchanged. Drivers that expose directory operations may additionally implement DirectoryDriver:

- makeDirectory(key): create a directory and missing parent directories.
- listDirectory(key): return immediate children only; an empty key represents the driver root.
- deleteDirectory(key, recursive): remove an empty directory by default; recursive removal requires explicit opt-in.

Directory listings return typed StorageDirectoryEntry values. The local storage root cannot be deleted.

## 3. Configuration and registration

The configured local root is read from storix.drivers.local.root. The package default is storage/app/storix within the Laravel application storage directory. Unicode case folding and canonical normalization are provided by the package's declared Symfony polyfills.

StorixServiceProvider registers a singleton LocalStorageDriver and registers it in DriverManager under the stable name local. The existing default-driver setting chooses which registered driver resolves when no name is supplied.

Applications may register custom drivers through DriverManager. Storix does not silently fall back when a requested driver is unavailable.

## 4. Storage-key security

A storage key is a relative, opaque POSIX-style key, not an operating-system path or a public URL.

The local driver rejects:

- Absolute paths and leading or trailing separators.
- Empty path segments, dot segments, and parent-directory traversal segments.
- Backslashes, null bytes, control characters, and platform-reserved filename characters.
- Windows device names, including names with extensions.
- Windows superscript device aliases, including COM¹, COM², COM³, LPT¹, LPT², and LPT³.
- Windows console device aliases CONIN$ and CONOUT$.
- Segments ending in a dot or space.
- The reserved .storix-tmp- namespace used for internal staging files.

The driver builds paths only below the configured root and checks existing path components for symbolic links. It rejects an existing path segment that differs only by Unicode case folding or canonical Unicode normalization from the requested segment, preventing distinct keys from silently aliasing on case-insensitive or normalization-insensitive filesystems. It does not follow symbolic links during directory listing or recursive deletion, and it refuses to delete the configured root. Public exception messages do not disclose absolute filesystem paths. Case-collision detection currently enumerates sibling names for each path segment; benchmark large flat directories and tune this cost in E11 before claiming high-cardinality directory performance.

These checks mitigate traversal and static symlink escapes. They do not guarantee race-free protection against a hostile local process concurrently replacing filesystem entries between checks and I/O. The configured storage root must be writable only by trusted application processes.

## 5. Streaming and finalization

### Writes

- Read the caller's input resource from its current stream position.
- Do not rewind or close the caller-owned input resource.
- Copy bytes in fixed 1 MiB chunks; do not accumulate the complete object in PHP memory.
- Calculate SHA-256 incrementally while writing.
- On POSIX systems, reserve a staging file in the destination directory with tempnam; on Windows, generate a unique .storix-tmp- name and create it with exclusive mode so the full reserved prefix is preserved.
- Require the temporary file to remain in the destination directory; reject a system-temp fallback outside it.
- POSIX tempnam creation provides private 0600 permissions. On Windows, the staging file inherits the destination directory's access-control list (ACL).
- Flush and close the staging file before renaming it to the final key.
- Remove the temporary file on failure. Do not replace an existing destination until the new stream has been written successfully.

Finalization uses a same-directory rename. Atomic replacement depends on the semantics of the underlying local filesystem. This describes atomic visibility, not crash durability; the driver does not explicitly fsync the file or parent-directory metadata.

### Reads

- A full read returns a direct readable file stream positioned at the requested offset.
- A bounded range read copies only the requested bytes into php://temp.
- The range stream uses a 2 MiB memory threshold; larger ranges spill to system temporary storage rather than growing PHP memory without a bound.
- The caller owns and must close the returned stream.
- The driver does not load the full object into a PHP string.

### Metadata

A write result includes byte count, caller-supplied or detected MIME type, SHA-256 checksum, modification time, visibility value, and scalar metadata.

A later stat call does not re-hash the object; its checksum remains unset unless a future implementation explicitly verifies it.

The local visibility field is metadata only. It does not publish objects through a web server or change operating-system permissions.

## 6. Failure semantics

- Missing exists checks return false.
- Missing stat calls return null.
- Deleting a missing object or directory is a no-op.
- Lookup beneath an existing regular-file parent treats the requested child as missing: exists returns false, stat returns null, and delete is a no-op. Writes below a regular-file parent fail.
- Read, write, stat, list, and delete failures use StorageOperationFailedException with STORAGE_OPERATION_FAILED.
- Invalid keys fail validation with InvalidArgumentException.
- Non-recursive deletion of a non-empty directory fails.
- Recursive directory deletion performs a read-only tree scan and aborts before deleting entries if a symbolic link is present. A concurrent local filesystem mutation after the scan remains outside the guarantee.
- Filesystem paths are not included in public operation-failure messages.

## 7. Test requirements

The E04 feature suite covers:

- Provider registration, configured root resolution, and default driver selection.
- Binary stream writes, byte counts, SHA-256 checksums, MIME metadata, and source-stream ownership.
- Starting a write from the caller's current stream position.
- Overwrite and staging-file cleanup behavior.
- Full reads, offset reads, and bounded range reads.
- Metadata, existence, deletion, and missing-object behavior.
- Directory creation, immediate listing, non-recursive deletion, and explicit recursive deletion.
- Traversal, absolute, malformed, reserved, and platform-specific key rejection.
- Windows device names, superscript aliases, and console aliases.
- Rejection of final-component and intermediate-directory symlinks.
- Symlink omission from directory listings.
- Recursive deletion pre-scan failure when a symlink is present, preserving regular sibling files and the outside target.
- Rejection of Unicode case-folding and canonical-normalization aliases for existing directory and object path segments.
- Missing-object lookup semantics when an intermediate parent is a regular file.
- Stable package error codes and safe error messages.
- Advertised capabilities.

CI targets PHP 8.5 and Laravel 13 with Composer validation, Laravel Pint, and PHPUnit. The CI workflow is the source of truth for current test and assertion totals; this document intentionally does not hard-code those counts.

E03 database compatibility remains separately scoped. E04 CI does not claim MySQL or PostgreSQL coverage beyond the documented E03 database matrix.

## 8. Out of scope

- Upload sessions and storix_files / storix_transfers orchestration (E05).
- Chunk planning, parallel chunk writes, and persisted chunk transmission (E06).
- Resume and fingerprint reconciliation (E07).
- Retry workers, locks, and stale-session recovery (E08).
- Transfer download orchestration and end-to-end integrity (E09).
- Telegram, S3-compatible, R2, REST, user interface, and serverless integrations.
- A guaranteed 50–100 GB maximum, unlimited size, or guaranteed throughput.

Actual size and throughput depend on the host filesystem, available disk space, quotas, PHP stream behavior, and operating-system limits. Large-file readiness must be established with representative integration and performance tests rather than inferred from streaming code alone.

## 9. Acceptance criteria

- [x] Local driver implements the E02 storage contract.
- [x] Local driver is registered by the Laravel service provider.
- [x] Optional directory operations have a typed contract and result DTO.
- [x] Streamed writes use bounded chunks and calculate SHA-256.
- [x] Writes finalize through a private same-directory temporary file and rename.
- [x] Full and bounded range reads return caller-owned streams.
- [x] Key validation rejects traversal, absolute paths, reserved names, and console aliases.
- [x] Symlink checks cover path resolution, listing, and recursive deletion.
- [x] Object metadata, existence, and deletion behavior are implemented.
- [ ] Latest E04 feature suite passes CI; consult the workflow for exact test and assertion totals.
- [ ] Latest review findings are addressed and all review threads are resolved.
- [ ] E04 is merged into main.
