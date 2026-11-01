# Hardening — Strict Storage-Level Fencing (Issue #11)

## Status

Implementation branch: `feature/e11-storage-fencing`, based on the verified E08 head. The first implementation slice defines the attempt-fenced driver contract and adds local-driver attempt-key, multipart-session, and cleanup isolation. UploadManager generation persistence, transactional winning-reference promotion, crash recovery, and delayed-operation certification remain incomplete. This work is isolated from E08/E09. No provider is considered fully strict-fenced until the complete delayed-operation acceptance suite passes.

## Problem statement

A transfer lease is a database ownership mechanism. It cannot revoke a write, multipart part, multipart completion, abort, or delete that a provider accepted before the lease expired. A timeout, process exit, heartbeat, or later `stat()` returning no object does not prove that the provider operation has stopped or cannot publish later.

The current driver API exposes mutable object keys and provider multipart sessions. It does not expose a storage-enforced fencing generation or conditional publication primitive. Therefore E08's lease and `cleanup_pending` marker are useful safety controls, but cannot guarantee strict no-overlap at the storage boundary.

## Required architecture

### 1. Immutable attempt identity

Each upload attempt receives:
- a monotonically increasing generation scoped to the Storix transfer;
- a cryptographically random attempt identifier;
- a unique immutable object namespace/key;
- a provider multipart session that belongs only to that attempt.

An attempt must never write into another attempt's object key or multipart session. A resumed request after ownership has changed must not reuse the prior attempt's mutable multipart session.

### 2. Publication indirection

The final object is not published by writing over a shared mutable key. Storix stores the winning attempt's immutable object key as the file's committed storage reference only in the same database transaction that:
- locks the transfer row;
- verifies the current lease token and fencing generation;
- marks the file available;
- marks the transfer completed.

Readers resolve the committed key from the StoredFile record. An abandoned attempt may finish late, but its immutable object key is never the committed reference.

If a provider integration needs a provider-side canonical key, it must expose an atomic conditional publish/CAS operation. A plain copy followed by delete is not an atomic publication primitive.

### 3. Attempt-scoped cleanup

Abort and delete operations must address only the exact attempt namespace/session. Cleanup for generation N must not be able to delete, abort, overwrite, or mutate generation N+1. Cleanup must be idempotent and safe if an old provider request completes after cleanup was requested.

### 4. Explicit driver guarantees

Strict mode must fail closed unless the driver explicitly advertises and implements:
- immutable attempt writes;
- attempt-isolated multipart sessions;
- atomic/conditional publication or database-reference publication semantics;
- attempt-scoped abort and delete;
- safe orphan enumeration/cleanup or an equivalent bounded cleanup mechanism.

A generic `supportsAtomicFinalize` flag is not sufficient evidence for these guarantees. Drivers without the contract remain usable in compatibility mode but must not claim strict no-overlap.

## Implementation sequence

1. **Contract and persistence:** add attempt identity/generation to transfer state and define an explicit driver fencing contract. Add migration rollback/reapply and compatibility tests.
2. **Upload path:** allocate a new attempt namespace before provider I/O; persist it before the first provider call; ensure every write and multipart operation uses that namespace.
3. **Commit path:** atomically commit only the current attempt's object reference after lease + generation validation. A stale attempt cannot mark a file available.
4. **Recovery path:** reconcile abandoned attempt namespaces without touching the committed attempt; never infer provider-operation termination from a missing-object observation.
5. **Local driver:** implement and test local filesystem attempt isolation and atomic committed-reference semantics.
6. **Remote driver contract tests:** provide reusable contract tests for delayed writes, multipart part writes, completion, abort, and delete. No remote adapter is certified without running them against its actual provider.
7. **Documentation and rollout:** strict mode defaults off until the configured driver is certified; enabling it with an uncertified driver must fail before accepting an upload.

## Required acceptance tests

- Hold an old write open after provider acceptance; expire its lease; let a new attempt publish; release the old write. The committed file reference and bytes remain the new attempt's.
- Repeat with a delayed multipart part and delayed multipart completion.
- A delayed old abort cannot abort the new attempt's multipart session.
- A delayed old delete cannot remove the new attempt's object.
- Process/container restart leaves enough persisted attempt metadata to recover or safely quarantine orphaned attempts.
- Repeated cleanup is idempotent; cleanup of generation N cannot affect N+1.
- A driver that lacks one required guarantee is rejected in strict mode before provider I/O.
- Run on SQLite, MySQL 8.4, and PostgreSQL 17.
- Migration rollback/reapply preserves pre-existing E06–E08 transfer/file records.

## Compatibility and safety

- E06–E08 behaviour remains unchanged until E11's strict mode is explicitly enabled and certified.
- Do not weaken `cleanup_pending` or clear it after a point-in-time missing-object check.
- Do not reuse mutable object keys or multipart sessions across attempt generations.
- Do not claim strict no-overlap for providers that cannot enforce the required semantics.
- No deployment, destructive provider cleanup, or production data migration is part of this branch.
- E09 remains blocked until E08 is formally accepted and its dependency chain is integrated.
