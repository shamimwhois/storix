# E07 — Resume & Idempotency

## Status

In progress. This specification is implemented incrementally on `feature/e07-resume-idempotency`, stacked on E06 because E06 PR #6 is still open. Do not merge this branch into `main` until E06 is merged and the stacked base is updated.

## Goals

- Make the request identity include the complete security scope.
- Preserve idempotency uniqueness across concurrent requests.
- Persist enough multipart session and chunk-verification metadata to reconcile after a process restart.
- Inspect provider-side parts and discard stale or mismatched local parts before retrying a part number.
- Resume only when the staged payload, owner/tenant scope, destination, driver and transfer plan match.
- Never trust a persisted provider reference without re-verifying the corresponding bytes.
- Keep the existing `Storix::uploads()->upload(UploadRequest)` API backward compatible.

## Non-goals

- Retry/backoff policy, stale-session sweeper and scheduled recovery (E08).
- Parallel chunk execution and queue orchestration.
- Download resume.
- Provider-specific multipart behavior beyond the optional `MultipartStorageDriver` contract.

## Identity and security scope

The canonical fingerprint is SHA-256 over a deterministic JSON document containing:

- Payload SHA-256 and byte length.
- Original filename.
- Tenant ID and owner ID (including explicit null values).
- Driver name and directory ID.
- MIME type and visibility.
- Metadata with recursively deterministic key ordering.

The database idempotency scope remains a SHA-256 digest of the tenant/owner tuple. The unique pair `(idempotency_scope, idempotency_key)` is the final concurrency guard; application-level lookup alone is insufficient.

An idempotency replay with a different fingerprint must fail with `IdempotencyConflictException`. A matching completed transfer may be replayed only if its file remains available and is still in the same scope.

## Durable multipart session

A transfer may persist an opaque provider multipart session identifier. Treat it as sensitive operational metadata: do not expose it in public DTOs, logs, events, API responses or exception messages.

Session identity is bound to the transfer's immutable storage key, driver and fingerprint. The session may be resumed only by the same driver and only after reconciliation verifies the persisted chunk layout.

## Chunk reconciliation

For every expected chunk index:

1. Compare persisted offset and size with the deterministic current plan.
2. A chunk is reusable only when its state is `verified`, its SHA-256 is valid, and its provider reference belongs to the persisted multipart session.
3. Re-stage the corresponding byte range from the newly supplied source stream and recompute SHA-256.
4. If the digest differs, invalidate that chunk and do not reuse its provider reference.
5. Never infer completion from byte counters alone.
6. Recompute transfer progress from verified chunks; do not increment counters blindly during reconciliation.

Unknown, duplicate, out-of-range or mismatched chunk records make the session non-resumable and require safe failure/cleanup. Do not silently reshape a live session when chunk-size configuration changes.

## Concurrency

- Serialize idempotency acquisition using a database transaction and row-level lock where supported.
- Keep the unique database constraint as the race-safe invariant.
- Use a transfer-level lease before mutating session/chunk state. The repository now exposes an atomic conditional lease claim and token-checked release; expired leases may be claimed by a new worker.
- A second matching request must receive an in-progress response while a valid lease is held; it must not create another provider session.
- Lease acquisition primitives are implemented and tested, but UploadManager does not yet acquire/release them. End-to-end enforcement remains an integration task.
- Lease expiry policy and abandoned-session recovery are E08 responsibilities.

## Persistence additions

- Store opaque multipart session ID and session start timestamp on the transfer.
- Store a verified timestamp on each chunk.
- Preserve existing unique constraints on idempotency scope/key and transfer/chunk index.
- Add migration rollback coverage and test supported database drivers.

## Required tests

- Same key + same payload/scope replays completed transfer.
- Same key + changed bytes, metadata, filename, destination, driver or security scope conflicts.
- Concurrent same-key requests create one transfer.
- A matching in-progress transfer is not duplicated.
- Restart reconciliation reuses only chunks whose staged bytes match persisted SHA-256 and session identity.
- Changed chunk size/layout invalidates the session rather than corrupting it.
- Missing, duplicate and out-of-range chunk rows fail closed.
- Progress counters are recomputed from verified chunks.
- Expired/abandoned sessions are handed to E08 recovery, not resumed blindly.

## Implementation sequence

1. Add durable session and verification timestamps.
2. Include tenant/owner security scope in the canonical request fingerprint.
3. Add repository-level atomic idempotency acquisition and transfer lease claim/release operations.
4. Implement chunk and provider-session reconciliation with fail-closed validation.
5. Integrate lease acquisition, resumable multipart session reuse and progress recomputation into UploadManager.
6. Add process-restart, concurrent-worker and regression tests; update docs and roadmap.
