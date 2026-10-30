# 04 — Composer Package Structure

```text
storix/
├── composer.json
├── README.md
├── LICENSE
├── CHANGELOG.md
├── phpunit.xml
├── pint.json
├── config/
│   └── storix.php
├── database/
│   └── migrations/
├── src/
│   ├── StorixServiceProvider.php
│   ├── StorixManager.php
│   ├── Facades/Storix.php
│   ├── Contracts/
│   │   ├── StorageDriver.php
│   │   ├── DirectoryDriver.php
│   │   └── Repositories/
│   │       ├── DirectoryRepository.php
│   │       ├── StoredFileRepository.php
│   │       ├── TransferRepository.php
│   │       └── ChunkRepository.php
│   ├── DTOs/
│   │   ├── DriverCapabilities.php
│   │   ├── WriteStreamRequest.php
│   │   ├── ReadStreamRequest.php
│   │   ├── DeleteObjectRequest.php
│   │   ├── StatObjectRequest.php
│   │   ├── StorageObjectMetadata.php
│   │   ├── WriteResult.php
│   │   ├── StorageDirectoryEntry.php
│   │   ├── UploadRequest.php
│   │   └── UploadResult.php
│   ├── Enums/
│   │   ├── ObjectVisibility.php
│   │   ├── TransferDirection.php
│   │   ├── TransferState.php
│   │   ├── ChunkState.php
│   │   └── StorixErrorCode.php
│   ├── Models/
│   │   ├── Directory.php
│   │   ├── StoredFile.php
│   │   ├── Transfer.php
│   │   └── Chunk.php
│   ├── Repositories/Eloquent/
│   │   ├── EloquentDirectoryRepository.php
│   │   ├── EloquentStoredFileRepository.php
│   │   ├── EloquentTransferRepository.php
│   │   └── EloquentChunkRepository.php
│   ├── Services/TransferStateMachine.php
│   ├── Uploads/UploadManager.php
│   ├── Exceptions/
│   │   ├── InvalidTransferStateTransition.php
│   │   ├── StorixException.php
│   │   ├── DriverNotFoundException.php
│   │   ├── UnsupportedCapabilityException.php
│   │   ├── StorageOperationFailedException.php
│   │   ├── IdempotencyConflictException.php
│   │   ├── UploadInProgressException.php
│   │   └── UploadTooLargeException.php
│   └── Drivers/
│       ├── DriverManager.php
│       └── Local/LocalStorageDriver.php
├── tests/
│   ├── Unit/
│   └── Feature/
└── docs/
```

## E03 responsibilities

- Four package migrations persist directories, files, transfers, and chunks.
- UUID-backed Eloquent models expose relationships and explicit casts.
- Repository contracts and Eloquent adapters enforce owner/tenant scope in SQL.
- TransferStateMachine validates lifecycle transitions inside database transactions.

## E02 responsibilities

- StorageDriver: provider-neutral stream read/write, delete, exists, stat, and capability reporting.
- DriverCapabilities: validated size, chunk, concurrency, streaming, range, multipart, copy, finalization, and pause/resume declarations.
- Request/result DTOs: typed boundaries; reject invalid keys, stream resources, offsets, lengths, sizes, and checksums.
- DriverManager: explicit driver registration and deterministic resolution; unknown drivers raise a stable package exception.
- StorixManager and Facades/Storix: public container/facade entry points for driver resolution and capability discovery.
- StorixServiceProvider: merge configuration and register singleton service bindings.

## E04 responsibilities

- LocalStorageDriver implements stream-based file operations against the configured local root.
- Local key validation rejects traversal and unsafe path segments; temporary writes finalize through same-directory rename.
- DirectoryDriver is an optional extension for directory creation, immediate listing, and explicit recursive deletion.
- Local reads support full streams and bounded offset/length ranges; writes calculate SHA-256 without buffering the full object in PHP memory.

## Deferred responsibilities

- UploadManager, TransferOrchestrator, chunk planner, retry/recovery, and integrity verification are later epics.
- E03 owns metadata persistence and transfer state.
- E04 owns the concrete local filesystem driver.
- E12 owns the optional Telegram adapter after current official constraints are verified.

## Composer requirements

Package name shamimstack/storix; namespace ShamimStack\\Storix. Declare PHP ^8.5 and Laravel 13 dependencies using exact framework package constraints. Use PSR-4 autoloading. Keep optional integrations in suggest/optional packages where feasible; do not make a frontend dependency mandatory.

## E05 responsibilities

- UploadManager stages, validates, fingerprints and persists a single-object upload.
- UploadRequest is stream-first and never transfers ownership of the caller's resource.
- UploadResult returns the persisted file and completed transfer, including whether this was an idempotent replay.
- E05 independently verifies stored size and SHA-256 by streaming the object back before marking it available.
- Chunk planning, resumability, background retry and REST endpoints remain deferred.
