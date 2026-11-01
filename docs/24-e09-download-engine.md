# E09 — Download Engine

## Scope

E09 adds scoped, integrity-checked downloads over the provider-neutral StorageDriver::readStream() contract. It supports full-object streaming and byte ranges only when the selected driver advertises supportsStreaming and supportsRangeReads respectively.

## API

Resolve the download service through Storix::downloads() and pass a typed DownloadRequest. The result exposes a readable PHP stream and metadata including content length, range start, partial-response state, and checksum verification. The caller owns the returned stream and must close it with DownloadResult::close() or fclose().

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
- For a range, Storix obtains the native driver range and compares its byte count and digest with the corresponding slice from the verified full-object snapshot. This detects provider mutation between verification and range retrieval.
- Each download is subject to storix.downloads.max_size_bytes (default 1 GiB), checked from provider metadata before any object read. Set it to null only when the application deliberately accepts unbounded per-request spool size and has its own admission/concurrency controls.
- Spool memory is bounded by storix.downloads.spool_memory_bytes; PHP spills larger streams to its temporary-file backing store. Operators must size and monitor temporary storage for concurrent downloads.
- Source and intermediate streams are closed on success and failure. The returned stream remains caller-owned and must be closed.

E09 does not create public URLs, alter visibility, or bypass application authorization. Applications must authenticate and authorize the caller before invoking the package. HTTP response construction, Content-Disposition policy, caching, and signed URLs are adapter/application concerns.

## Configuration

    'downloads' => [
        'max_size_bytes' => 1024 * 1024 * 1024,
        'spool_memory_bytes' => 8 * 1024 * 1024,
    ],

## Acceptance coverage

The feature suite verifies full streaming, tenant/owner isolation, unavailable/missing file rejection, checksum and size mismatch rejection, native range capability enforcement, range boundaries, pre-read size-limit enforcement, and stream cleanup. The package database matrix validates service bindings and repository scoping on SQLite, MySQL 8.4, and PostgreSQL 17.
