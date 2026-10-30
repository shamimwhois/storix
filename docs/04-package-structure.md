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
│   ├── Contracts/StorageDriver.php
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
│   │   └── WriteResult.php
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
│   ├── Exceptions/
│   │   ├── InvalidTransferStateTransition.php
│   │   ├── StorixException.php
│   │   ├── DriverNotFoundException.php
│   │   └── UnsupportedCapabilityException.php
│   └── Drivers/DriverManager.php
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

## Deferred responsibilities

- UploadManager, TransferOrchestrator, chunk planner, retry/recovery, and integrity verification are later epics.
- E03 owns metadata persistence and transfer state.
- E04 owns the concrete local filesystem driver.
- E12 owns the optional Telegram adapter after current official constraints are verified.

## Composer requirements

Package name shamimstack/storix; namespace ShamimStack\\Storix. Declare PHP ^8.5 and Laravel 13 dependencies using exact framework package constraints. Use PSR-4 autoloading. Keep optional integrations in suggest/optional packages where feasible; do not make a frontend dependency mandatory.
