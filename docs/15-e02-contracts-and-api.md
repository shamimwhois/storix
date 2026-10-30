# E02 — Contracts & Public API

**Status:** Implementation in progress  
**Epic dependency:** E01 Foundation  
**Target:** PHP 8.5+ and Laravel 13.x  
**Package:** `shamimstack/storix`  
**Namespace:** `ShamimStack\\Storix`

## 1. Objective

E02 establishes the provider-neutral PHP boundary that every future storage backend must implement. It defines typed request/result objects, driver capabilities, stable package exceptions, driver registration and resolution, and the Laravel-facing manager/facade.

E02 does not implement a concrete storage backend, database persistence, upload orchestration, chunk execution, retries, or Telegram integration.

## 2. Public driver contract

The `StorageDriver` interface defines:

| Method | Contract |
|---|---|
| `name()` | Stable driver identifier |
| `capabilities()` | Immutable `DriverCapabilities` value |
| `writeStream(WriteStreamRequest)` | Write a readable stream and return `WriteResult` |
| `readStream(ReadStreamRequest)` | Return a readable PHP stream resource |
| `delete(DeleteObjectRequest)` | Delete an object; missing-object behavior must be documented consistently |
| `exists(string $key)` | Check whether an opaque storage key exists |
| `stat(StatObjectRequest)` | Return metadata or `null` when absent |

Driver implementations must not leak provider SDK classes into the public contract. Stream ownership, lifetime, seekability, and failure behavior must be documented by each driver.

## 3. DTO inventory

| DTO | Responsibility |
|---|---|
| `WriteStreamRequest` | Key, input stream, MIME type, visibility, metadata |
| `ReadStreamRequest` | Key and optional byte offset/length |
| `DeleteObjectRequest` | Target key and deletion options |
| `StatObjectRequest` | Metadata lookup target |
| `StorageObjectMetadata` | Provider-neutral object metadata |
| `WriteResult` | Provider-neutral result of a completed write |
| `DriverCapabilities` | Immutable feature and limit declaration |

DTO constructors validate invalid keys, stream resources, negative offsets, invalid lengths, and inconsistent capability limits. Metadata must remain provider-neutral and must not contain credentials or private provider references.

## 4. Capability model

`DriverCapabilities` declares:

- maximum object size, when bounded;
- minimum, preferred, and maximum chunk sizes;
- maximum concurrency;
- streaming support;
- range-read support;
- multipart-write support;
- server-side-copy support;
- atomic-finalize support;
- pause/resume support.

The constructor enforces positive size/concurrency limits and the invariant:

`1 <= minChunkSizeBytes <= preferredChunkSizeBytes <= maxChunkSizeBytes`

The transfer engine in later epics must consult these declarations rather than assume every backend supports the same operations.

## 5. Driver registration and resolution

The `DriverManager` owns an in-memory registry of `StorageDriver` implementations.

```php
use ShamimStack\\Storix\\Contracts\\StorageDriver;
use ShamimStack\\Storix\\Drivers\\DriverManager;

$manager = app(DriverManager::class);
$manager->register('custom', app(CustomStorageDriver::class));

$driver = $manager->driver('custom');
```

Registration names must be non-empty and match `$driver->name()`. Resolution uses the requested name or the configured default. Unknown names raise `DriverNotFoundException`; the manager must not silently fall back to another backend.

The manager exposes a sorted list of registered driver names. Registration is process/container scoped; durable driver configuration and persistence are outside E02.

## 6. Laravel public entry point

The service provider binds `DriverManager` and the `storix` manager in Laravel's service container. The facade exposes:

```php
use ShamimStack\\Storix\\Facades\\Storix;

$driver = Storix::driver('custom');
$capabilities = Storix::capabilities('custom');
$registered = Storix::drivers();
```

No concrete driver is registered by E02. Therefore the default `local` driver is expected to raise `DriverNotFoundException` until E04 implements and registers the Local driver.

## 7. Error model

All package-level exceptions extend `StorixException`, which carries a stable `StorixErrorCode` and may preserve a previous exception.

Current codes:

- `DRIVER_NOT_FOUND`
- `INVALID_REQUEST`
- `UNSUPPORTED_CAPABILITY`
- `STORAGE_OPERATION_FAILED`

Errors must not expose credentials, raw provider responses, internal filesystem paths, or stack traces through public API responses.

## 8. Acceptance criteria

- [ ] All public contracts use strict types and provider-neutral PHP types.
- [ ] DTOs reject invalid input at construction.
- [ ] Capability limits reject inconsistent configurations.
- [ ] Driver registration rejects empty or mismatched names.
- [ ] Explicit and default driver resolution work deterministically.
- [ ] Unknown drivers raise the stable package exception.
- [ ] Facade and container bindings resolve through Laravel.
- [ ] Unit and feature tests cover success, invalid input, missing driver, and service binding.
- [ ] Pint, Composer validation, and the complete PHPUnit suite pass in CI.
- [ ] README, API documentation, and changelog accurately distinguish implemented contracts from planned storage/transfer features.

## 9. Out of scope

E02 must not add migrations, Eloquent models, repositories, upload sessions, chunk persistence, a Local driver, Telegram APIs, or a REST controller. Those concerns belong to later roadmap epics.

## 10. Next dependency

After E02 is reviewed and merged, E03 implements durable persistence and transfer state. E04 implements the first concrete Local storage driver against the E02 contract.
