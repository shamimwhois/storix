# 05 — Core PHP API and Optional REST API

## 1. PHP API (primary interface)

Illustrative consumer usage:

```php
use ShamimStack\Storix\Facades\Storix;

$file = Storix::files()->put(
    source: $uploadedFile,
    directory: 'invoices/2026',
    options: ['visibility' => 'private']
);

$stream = Storix::files()->readStream($file->id);
Storix::files()->delete($file->id);

$transfer = Storix::uploads()->start($source, [
    'directory' => 'backups',
    'idempotency_key' => $requestId,
]);

$status = Storix::transfers()->status($transfer->id);
Storix::transfers()->resume($transfer->id);
```

The exact signatures should be finalized as typed DTOs before implementation; do not expose unvalidated raw arrays throughout the domain layer.

## 2. Suggested REST routes

Routes are optional and disabled unless configured.

| Method | Route | Purpose |
|---|---|---|
| POST | `/api/v1/storix/uploads` | Create upload session |
| POST | `/api/v1/storix/uploads/{id}/chunks` | Submit a chunk |
| GET | `/api/v1/storix/transfers/{id}` | Transfer status/progress |
| POST | `/api/v1/storix/transfers/{id}/resume` | Resume eligible transfer |
| POST | `/api/v1/storix/transfers/{id}/pause` | Pause if supported |
| POST | `/api/v1/storix/transfers/{id}/cancel` | Cancel |
| POST | `/api/v1/storix/transfers/{id}/retry` | Request retry |
| GET | `/api/v1/storix/files` | List authorized files |
| GET | `/api/v1/storix/files/{id}` | File metadata |
| GET | `/api/v1/storix/files/{id}/download` | Stream/download |
| DELETE | `/api/v1/storix/files/{id}` | Delete |
| POST | `/api/v1/storix/directories` | Create directory |
| GET | `/api/v1/storix/directories` | List directories |
| PATCH | `/api/v1/storix/directories/{id}` | Rename/move |
| DELETE | `/api/v1/storix/directories/{id}` | Delete if empty |

## 3. Response envelope

```json
{
  "data": {
    "id": "01J...",
    "state": "transferring",
    "total_bytes": 104857600,
    "transferred_bytes": 52428800,
    "remaining_bytes": 52428800,
    "progress_percent": 50.0,
    "speed_bytes_per_second": 5242880,
    "eta_seconds": 10,
    "completed_chunks": 8,
    "total_chunks": 16
  },
  "meta": {
    "request_id": "req_...",
    "api_version": "v1"
  }
}
```

Do not return internal filesystem paths, bot tokens, provider credentials, raw exception traces or private storage references.

## 4. Error format

```json
{
  "error": {
    "code": "TRANSFER_RETRYABLE_FAILURE",
    "message": "The transfer could not continue and will be retried.",
    "retryable": true,
    "retry_after_seconds": 5
  },
  "meta": {"request_id": "req_..."}
}
```

Use stable machine-readable error codes. HTTP status mapping: 400 invalid input, 401 unauthenticated, 403 unauthorized, 404 inaccessible/not found, 409 conflicting state/idempotency conflict, 413 too large, 422 validation failure, 429 rate limited, 500 unexpected internal error, 503 provider unavailable.

## 5. API rules

- Authenticate through the host application's configured Laravel guard.
- Authorize every object by tenant and owner scope.
- Validate content length, chunk index, expected offset, MIME policy and checksum.
- Use idempotency keys for create/chunk operations.
- Paginate listing endpoints.
- Use signed URLs only if explicitly enabled and short-lived.
- Rate-limit by user/tenant and endpoint.
- Version the API independently from package version.


---

## 6. E02 public driver API (implemented contract surface)

E02 introduces the provider-neutral driver boundary and driver resolution API. The currently available public entry points are:

```php
use ShamimStack\\Storix\\Facades\\Storix;

// Resolve a registered driver (or the configured default).
$driver = Storix::driver();
$capabilities = Storix::capabilities();

// List names explicitly registered in the current application container.
$registeredDrivers = Storix::drivers();
```

Driver implementations are registered through the DriverManager service:

```php
use ShamimStack\\Storix\\Contracts\\StorageDriver;
use ShamimStack\\Storix\\Drivers\\DriverManager;

app(DriverManager::class)->register('custom', app(CustomStorageDriver::class));
$driver = Storix::driver('custom');
```

The E02 StorageDriver contract accepts typed request objects for stream writes/reads, deletion and metadata lookup. `readStream()` returns a readable PHP stream resource; the concrete driver is responsible for documenting and enforcing its stream lifetime and seekability behavior.

`DriverCapabilities` declares maximum object size, chunk-size bounds/preference, concurrency, streaming, range-read, multipart-write, server-side-copy, atomic-finalize and pause/resume support. The constructor rejects inconsistent size/chunk/concurrency limits.

This is a contract and resolution layer only. No concrete driver is registered by E02, so resolving the default `local` driver will raise `DriverNotFoundException` until E04 adds and registers the Local driver. File/upload manager APIs shown earlier in this document remain planned and are not part of E02.
