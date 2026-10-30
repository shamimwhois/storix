# E03 — Persistence Layer

**Status:** In progress  
**Branch:** `feature/e03-persistence-layer`  
**Dependencies:** E01 Foundation, E02 Contracts & API  
**Compatibility target:** PHP 8.5+ / Laravel 13.x

## 1. Purpose and boundaries

E03 introduces durable relational persistence for Storix. The relational database is the source of truth for file and directory metadata, transfer lifecycle state, idempotency records, and chunk verification state.

E03 provides four core entities:

1. `storix_directories`
2. `storix_files`
3. `storix_transfers`
4. `storix_chunks`

E03 includes migrations, Eloquent models, repository contracts and database-backed implementations, container bindings, a transfer state machine, and tests.

E03 does **not** implement a storage driver, provider API, actual file upload/download, chunk workers, retry scheduling, REST endpoints, share links, or Telegram integration. Those belong to later epics.

## 2. Design principles

- Use UUID primary keys for Storix-owned records. UUIDs are identifiers, not authorization.
- Do not add foreign keys from package tables to the host application's users or tenants tables. Host applications may use integer IDs, UUIDs, ULIDs, or custom identity models.
- Store host principal identifiers as nullable strings and derive a stable `scope_key` for scoped uniqueness and lookup.
- Persist byte counts as unsigned big integers and timestamps in UTC.
- Keep provider credentials and secrets out of metadata tables.
- Treat provider storage keys as opaque private references, never as public URLs.
- Keep provider I/O outside database transactions.
- Use row locks or compare-and-set updates for state transitions.
- Cache and Redis are optimizations; database rows remain durable truth.
- Use portable Laravel schema APIs and document database-specific constraints.

## 3. Scope identity and tenant isolation

Each file, directory, and transfer has a required `scope_key` (64-character SHA-256 digest) derived by the package from the effective principal scope. It is paired with nullable `tenant_ref` and `owner_ref` strings for traceability.

The scope resolver must distinguish at least:

- tenant-owned data;
- user-owned data outside a tenant;
- explicitly configured system-owned data.

The resolver must not silently treat a missing owner as globally accessible. System scope must be an explicit, separately authorized mode. A scope key is an index/uniqueness aid, not an access token; every application operation must still authorize the caller.

## 4. Schema

### 4.1 Directories — `storix_directories`

| Column | Type / rule | Purpose |
|---|---|---|
| `id` | UUID primary key | Public package identifier |
| `scope_key` | char(64), indexed | Effective owner/tenant boundary |
| `tenant_ref` | nullable varchar(128) | Host tenant identifier |
| `owner_ref` | nullable varchar(128) | Host owner identifier |
| `parent_id` | nullable UUID FK, restrict on delete | Parent directory |
| `driver` | varchar(64) | Driver assigned to directory |
| `name` | varchar(255) | Display segment |
| `path_key` | varchar(1024) | Normalized virtual path |
| `status` | varchar(24) | active / deleting / deleted |
| timestamps | UTC | Lifecycle timestamps |
| `deleted_at` | nullable timestamp | Soft deletion |

Indexes: `(scope_key, driver, path_key)`, `(scope_key, parent_id, name)`, and `(status, updated_at)`. Path normalization and cycle prevention are application-layer responsibilities. Do not rely on nullable multi-column unique constraints for correctness across database engines.

### 4.2 Files — `storix_files`

| Column | Type / rule | Purpose |
|---|---|---|
| `id` | UUID primary key | Public package identifier |
| `scope_key` | char(64), indexed | Effective owner/tenant boundary |
| `tenant_ref`, `owner_ref` | nullable varchar(128) | Host principal references |
| `directory_id` | nullable UUID FK, restrict on delete | Virtual directory |
| `driver` | varchar(64) | Storage backend |
| `storage_key` | text | Opaque private provider key |
| `name`, `original_name` | varchar(255) | Display and source names |
| `extension` | nullable varchar(32) | Normalized extension |
| `mime_type` | nullable varchar(255) | Detected/declared MIME type |
| `size_bytes` | unsigned big integer | Logical file size |
| `sha256` | nullable char(64) | Final content checksum |
| `visibility` | varchar(16) | private / public |
| `status` | varchar(24) | pending / processing / available / deleting / deleted / failed |
| `metadata` | nullable JSON | Non-secret extensible metadata |
| timestamps | UTC | Lifecycle timestamps |
| `deleted_at` | nullable timestamp | Soft deletion |

Indexes: `(scope_key, created_at)`, `(scope_key, directory_id, name)`, `(driver, status)`, and `(scope_key, sha256, size_bytes)`. Metadata must never contain credentials, raw access tokens, or unredacted provider responses.

### 4.3 Transfers — `storix_transfers`

| Column | Type / rule | Purpose |
|---|---|---|
| `id` | UUID primary key | Transfer identifier |
| `scope_key` | char(64), indexed | Idempotency/access scope |
| `tenant_ref`, `owner_ref` | nullable varchar(128) | Host principal references |
| `file_id` | nullable UUID FK, restrict on delete | Related logical file |
| `direction` | varchar(16) | upload / download |
| `driver` | varchar(64) | Selected backend |
| `state` | varchar(24) | State-machine value |
| `source_name` | nullable varchar(255) | Original source name |
| `total_bytes` | unsigned big integer | Expected total bytes |
| `transferred_bytes` | unsigned big integer | Verified/transferred byte count |
| `total_chunks` | unsigned integer | Planned chunk count |
| `completed_chunks` | unsigned integer | Verified chunk count |
| `average_bytes_per_second` | nullable unsigned big integer | Derived metric |
| `eta_seconds` | nullable unsigned big integer | Derived estimate |
| `idempotency_key` | nullable varchar(128) | Client retry identity |
| `fingerprint` | nullable char(64) | Source fingerprint, not a global key |
| `attempts`, `max_attempts` | unsigned integer | Attempt budget |
| `last_error_code` | nullable varchar(64) | Sanitized machine code |
| `last_error_message` | nullable varchar(512) | Sanitized operational message |
| `started_at`, `finished_at`, `expires_at` | nullable timestamps | Lifecycle timestamps |
| timestamps | UTC | Persistence timestamps |

Indexes: `(scope_key, state, created_at)`, `(state, updated_at)`, `(file_id, created_at)`, and a scoped idempotency lookup index. Null idempotency keys are allowed; non-null keys must be unique within `scope_key`. Use a portable unique key strategy and test it on the supported database engines.

Counters must remain non-negative. `transferred_bytes` must not exceed `total_bytes`; `completed_chunks` must not exceed `total_chunks`. Enforce these invariants in the state/repository layer and add database check constraints where supported.

### 4.4 Chunks — `storix_chunks`

| Column | Type / rule | Purpose |
|---|---|---|
| `id` | UUID primary key | Chunk identifier |
| `transfer_id` | UUID FK, cascade on transfer delete | Parent transfer |
| `chunk_index` | unsigned integer | Zero-based sequence |
| `offset_bytes` | unsigned big integer | Starting byte offset |
| `size_bytes` | unsigned big integer | Expected chunk size |
| `sha256` | nullable char(64) | Verified chunk checksum |
| `state` | varchar(16) | pending / uploading / verified / failed |
| `attempts` | unsigned integer | Attempt count |
| `driver_reference` | nullable text | Opaque provider part reference |
| `last_error_code` | nullable varchar(64) | Sanitized error code |
| timestamps | UTC | Persistence timestamps |

Constraints/indexes: unique `(transfer_id, chunk_index)`, index `(transfer_id, state)`. Do not persist provider credentials in `driver_reference`. A chunk is `verified` only when actual byte count and checksum have been checked.

## 5. Relationships

- Directory has many child directories and files.
- File belongs to an optional directory and has many transfers.
- Transfer belongs to an optional file and has many chunks.
- Chunk belongs to exactly one transfer.

Restrict deletion of directories/files while dependent records exist; use soft deletion and explicit cleanup workflows. Deleting a transfer may cascade its chunk rows because chunks have no independent lifetime.

## 6. Transfer state machine

Allowed transitions:

| From | To |
|---|---|
| pending | preparing, cancelled, expired, failed |
| preparing | transferring, retry_wait, cancelled, failed |
| transferring | paused, retry_wait, verifying, cancelled, failed |
| paused | transferring, cancelled, expired |
| retry_wait | transferring, cancelled, failed, expired |
| verifying | completed, retry_wait, failed |

Terminal states: `completed`, `failed`, `cancelled`, `expired`. Terminal states cannot transition back to active states. A completed transfer must have been finalized by the driver and passed required integrity checks. E03 persists and validates state; actual orchestration is deferred.

State transitions must be validated by a dedicated state machine and persisted using a transaction with `lockForUpdate()` or an atomic compare-and-set update. Never hold this transaction open during filesystem/network operations.

## 7. Repository boundaries

Repository interfaces expose persistence behavior only:

- Directory repository: create/find/list/update soft-delete within a supplied scope.
- File repository: create/find/update metadata within a supplied scope.
- Transfer repository: create, find, scoped idempotency lookup, transition, and bounded state queries.
- Chunk repository: bulk-create planned chunks, find by index, record attempts, and mark verified after validation.

Application services depend on contracts; Eloquent implementations live under the infrastructure/persistence namespace. Repositories must require an explicit scope object for tenant-owned operations and must not infer access from a UUID alone.

## 8. Transactions and concurrency

- Use short transactions for related metadata writes.
- Dispatch future queue jobs only after commit.
- Lock transfer rows when performing non-trivial state transitions.
- Do not hold a DB transaction during slow provider I/O.
- Keep progress writes configurable and throttled.
- Use database uniqueness as the final guard for scoped idempotency.
- Treat cache locks as supplementary; use durable state and database constraints for correctness.

## 9. Migration portability

Use Laravel Schema Builder and portable column/index methods. Avoid dependence on partial indexes or nullable-unique behavior. If a database-specific optimization is added, retain a portable correctness path and cover it with integration tests. Migrations must support both `up()` and `down()`, including foreign-key order.

The package test baseline uses SQLite in-memory for fast tests; MySQL and PostgreSQL compatibility must be included in CI before claiming support for those engines.

## 10. Security

- UUIDs do not replace authorization.
- All reads and writes must constrain by the effective `scope_key`.
- Never mass-assign untrusted request input to Eloquent models.
- Sanitize errors before persistence.
- Do not store provider tokens, passwords, raw share tokens, or private signed URLs.
- Soft-delete and cleanup flows must not expose deleted data across scopes.

## 11. Acceptance checklist

- [ ] Four migrations execute and roll back in dependency order.
- [ ] UUID keys and explicit casts/relationships are correct.
- [ ] Host user/tenant IDs are not coupled to a particular database schema.
- [ ] Scoped idempotency rejects duplicate non-null keys.
- [ ] Directory/file/transfer repositories enforce explicit scope.
- [ ] Invalid transfer-state transitions are rejected.
- [ ] Concurrent transitions cannot overwrite newer state.
- [ ] Chunk index uniqueness and verification invariants are covered.
- [ ] Up/down migration and database feature tests pass.
- [ ] Pint, Composer validation, and full PHPUnit suite pass in CI.
- [ ] README, docs index, database design, package structure, and changelog match implementation.

## 12. Implementation sequence

1. Finish and commit E03 documentation separately.
2. Add migrations and schema/migration tests.
3. Add Eloquent models, casts, and relationship tests.
4. Add scope value object and repository contracts/implementations.
5. Bind repositories and state machine through the service provider.
6. Add state transition, idempotency, concurrency, and cross-scope tests.
7. Update package tree, README, docs portal, and changelog.
8. Run CI, review the diff, commit implementation separately, and open one E03 pull request.

E03 is complete only after the pull request is reviewed, required CI checks are green, and the merge into `main` is verified.
