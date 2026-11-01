# 07 — Storage Drivers

## 1. Driver contract

A driver must expose a stable provider-neutral contract for:
- `putStream`, `getStream`, `delete`, `exists`, `stat`;
- directory/prefix behavior where applicable;
- multipart/chunk operations or capability-declared fallback;
- range reads when supported;
- finalization/abort for multipart transfers;
- provider error classification;
- capability metadata.

Use typed request/result objects. Do not leak provider SDK response objects into the domain layer.

## 2. Capabilities

Each driver reports:
- maximum object size;
- preferred/minimum/maximum chunk size;
- supports streaming;
- supports range reads;
- supports multipart/chunked writes;
- supports server-side compose/copy;
- supports atomic finalize;
- supports pause/resume semantics;
- concurrency and rate-limit hints.

The transfer engine must adapt to capabilities rather than assume all drivers behave identically.

## 3. Local driver

Use Laravel filesystem/Flysystem where appropriate. Store files outside the public web root by default. Generate opaque storage keys, normalize paths and prevent traversal. Stream writes and reads. Atomic local finalization should write to a temporary key and rename when filesystem semantics permit. Document that local disk durability depends on host filesystem and backup strategy.

## 4. Telegram driver

Telegram is an optional provider driver, not the core storage abstraction. Telegram Bot API file handling has provider-specific size and retrieval constraints; these limits change and differ between the official Bot API and a locally hosted Bot API server. Do not hard-code historical limits as permanent facts. Before implementation/release:
1. verify official Telegram Bot API documentation and current changelog;
2. verify Local Bot API server behavior and deployment requirements;
3. verify permitted storage use, retention and content policy;
4. implement explicit provider limits and clear capability reporting;
5. add integration tests against a controlled bot/chat;
6. never expose bot token or private channel identifiers in API responses/logs.

Telegram Serverless is explicitly out of scope and must not appear as a dependency, driver or roadmap item.

## 5. Future drivers

S3-compatible providers, Cloudflare R2 or other backends can be added by implementing the same contract and passing the driver contract test suite. Do not design the core around Telegram-specific message IDs or file references.


---


## Multipart write capability (E06)

Multipart support is optional. supportsMultipartWrites must agree with implementation of MultipartStorageDriver. The transfer engine checks every returned part number, byte size and SHA-256 digest before accepting a chunk. The Local adapter stores parts in a randomly named private temporary directory, re-verifies all part contents during assembly, checks the aggregate size and SHA-256, and uses its existing atomic writeStream() path for final publication.

Local multipart execution is sequential and declares maxConcurrency = 1. The presence of multipart support does not imply parallel workers, resumability or durable sessions.
