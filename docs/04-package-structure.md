# 04 — Composer Package Structure

```text
storix/
├── composer.json
├── README.md
├── LICENSE
├── CHANGELOG.md
├── phpunit.xml
├── pint.json
├── phpstan.neon
├── config/
│   └── storix.php
├── database/
│   └── migrations/
├── routes/
│   └── api.php
├── src/
│   ├── StorixServiceProvider.php
│   ├── Facades/Storix.php
│   ├── Contracts/
│   │   ├── StorageDriver.php
│   │   ├── ChunkStore.php
│   │   ├── ProgressStore.php
│   │   └── LockManager.php
│   ├── DTOs/
│   ├── Enums/
│   ├── Exceptions/
│   ├── Models/
│   ├── Policies/
│   ├── Services/
│   │   ├── FileManager.php
│   │   ├── DirectoryManager.php
│   │   ├── UploadManager.php
│   │   ├── DownloadManager.php
│   │   └── TransferManager.php
│   ├── Drivers/
│   │   ├── DriverManager.php
│   │   ├── Local/
│   │   └── Telegram/
│   ├── Transfer/
│   │   ├── ChunkPlanner.php
│   │   ├── TransferOrchestrator.php
│   │   ├── RetryPolicy.php
│   │   ├── RecoveryService.php
│   │   └── IntegrityVerifier.php
│   ├── Jobs/
│   ├── Events/
│   ├── Listeners/
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Requests/
│   │   ├── Resources/
│   │   └── Middleware/
│   └── Console/Commands/
├── resources/
│   └── (no mandatory frontend assets)
├── tests/
│   ├── Unit/
│   ├── Feature/
│   ├── Contract/
│   ├── Integration/
│   ├── Security/
│   └── Performance/
└── docs/
```

## Responsibilities

- `StorageDriver`: provider-neutral operations and capability reporting.
- `DriverManager`: resolve configured driver; reject unknown drivers clearly.
- `UploadManager`: validate request, create/reuse session and coordinate upload.
- `TransferOrchestrator`: state transitions, queue dispatch and completion.
- `ChunkPlanner`: calculate offsets, sizes and chunk identifiers.
- `RecoveryService`: reconcile durable records after failure.
- `IntegrityVerifier`: SHA-256 validation for chunks and final object.
- `RetryPolicy`: classify errors and calculate bounded delay.
- `ProgressStore`: store/aggregate progress and ETA samples.
- `StorixServiceProvider`: merge config, register services, migrations, commands and optional routes.

## Composer requirements

Package name `shamimstack/storix`; namespace `ShamimStack\Storix`. Declare PHP `^8.5` and Laravel 13 dependencies using the exact framework package constraints confirmed during implementation. Use PSR-4 autoloading. Keep optional integrations in `suggest`/optional packages where feasible; do not make a frontend dependency mandatory.


---
