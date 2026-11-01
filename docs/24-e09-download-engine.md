# E09 — Download Engine

## Scope

E09 adds scoped, integrity-checked downloads over the provider-neutral StorageDriver::readStream() contract. It supports full-object streaming and byte ranges only when the selected driver advertises supportsStreaming and supportsRangeReads respectively.

## API

Resolve the download service through Storix::downloads() and pass a typed DownloadRequest. The result exposes a readable PHP stream and metadata including content length, range start, partial-response state, and checksum verification. The DownloadResult owns the returned stream. Keep the result alive while consuming the stream, then call DownloadResult::close(); this closes the stream and releases the OS-level download-admission slot. The destructor is a safety net that also closes the stream and releases the slot if the result is discarded.

Example full download:

    $result = Storix::downloads()->download(new DownloadRequest(
        fileId: $fileId,
        tenantId: $tenantId,
        ownerId: $ownerId,
    ));

    try {
        while (! feof($result->stream)) {
            echo fread($result->stream, 1024 * 1024);
        }
    } finally {
        $result->close();
    }

Example byte range (offset plus positive length):

    $result = Storix::downloads()->download(new DownloadRequest(
        fileId: $fileId,
        tenantId: $tenantId,
        ownerId: $ownerId,
        offset: 1024,
        length: 4096,
    ));

The requested range is clamped to the remaining object length. Offset and length must be supplied together. A range beginning at or beyond EOF is rejected.

## Security and integrity

- File lookup applies the exact tenant and owner scope supplied in DownloadRequest; an omitted scope matches only a null stored scope.
- Only records with status=available can be downloaded.
- The selected driver is derived from the persisted file record, not caller input.
- The driver must advertise streaming. Range requests additionally require advertised native range support.
- Storix checks provider metadata against the persisted file size and requires a valid persisted SHA-256 digest.
- Before returning bytes, Storix stages the full object through a bounded-memory php://temp stream, recomputes byte count and SHA-256, and compares both with the persisted record.
- For a range, Storix obtains the native driver range and verifies its byte count and digest against the corresponding slice from the verified full-object snapshot. The provider range stream is then closed. Storix returns a fresh immutable range snapshot copied from the already-verified full-object spool, so a mutable provider stream cannot change bytes after checksum verification.
- Each download is subject to storix.downloads.max_size_bytes (default 1 GiB), checked from provider metadata before any object read.
- An OS-level flock semaphore limits active downloads to storix.downloads.max_concurrent_downloads (default 1). The slot remains held until DownloadResult::close() and is automatically released by the operating system if the worker process exits. The lock directory is local to each host by default, which bounds that host's temporary storage. If multiple hosts share one temporary filesystem, configure a shared lock directory on a filesystem with reliable flock semantics. Set max_size_bytes or max_concurrent_downloads to more permissive values only after sizing temporary storage.
- Spool memory is bounded by storix.downloads.spool_memory_bytes; PHP spills larger streams to its temporary-file backing store. Peak temporary storage is bounded by the admission slots and the configured object-size ceiling; a range request can temporarily hold the verified object plus one provider range spool, then the verified object plus the immutable returned range snapshot. With defaults, the worst-case per-slot bound is approximately twice max_size_bytes.
- Source and intermediate streams are closed on success and failure. The returned stream remains caller-owned and must be closed.

E09 does not create public URLs, alter visibility, or bypass application authorization. Applications must authenticate and authorize the caller before invoking the package. HTTP response construction, Content-Disposition policy, caching, and signed URLs are adapter/application concerns.

## Configuration

    'downloads' => [
        'max_size_bytes' => 1024 * 1024 * 1024,
        'max_concurrent_downloads' => 1,
        'lock_directory' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'storix-download-locks-'.substr(hash('sha256', base_path()), 0, 16),
        'spool_memory_bytes' => 8 * 1024 * 1024,
    ],

## Acceptance coverage

The feature suite verifies full streaming, tenant/owner isolation, unavailable/missing file rejection, checksum and size mismatch rejection, native range capability enforcement, range boundaries, pre-read size-limit enforcement, admission-slot release, immutable range snapshots, and stream cleanup. The package database matrix validates service bindings and repository scoping on SQLite, MySQL 8.4, and PostgreSQL 17.
