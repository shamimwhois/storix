# Storix — Architecture Decision Register

## Confirmed

| Decision | Resolution |
|---|---|
| Product name | Storix |
| Composer package | `shamimstack/storix` |
| Namespace | `ShamimStack\Storix` |
| Tagline | One API. Every Storage. |
| PHP | 8.5+ |
| Laravel | 13.x |
| Product type | Backend-only Laravel package |
| Frontend | None bundled or required |
| Primary interface | PHP services/facade |
| REST API | Optional adapter |
| Storage model | Driver-based |
| Telegram Serverless | Explicitly excluded |
| Transfer UX support | Backend progress/events/status API; consumer chooses UI |
| Reliability | Chunking, resume, retry, recovery, integrity verification |

## Decisions requiring explicit engineering confirmation

1. Supported relational databases and minimum versions.
2. Whether the first public release ships only Local driver or also Telegram.
3. Whether encryption-at-rest is in v1 or deferred.
4. Whether share links are in v1 or a later optional module.
5. Exact REST authentication middleware and route ownership strategy.
6. Exact default maximum file size, chunk size and concurrency after benchmarks.
7. Whether deduplication is file-level only initially or chunk-level in v1.
8. Which Laravel queue drivers are in the support matrix.
9. Whether download progress is tracked for PHP stream consumers, HTTP consumers, or both.

Do not silently decide these product-level items. Recommend a default with trade-offs and record the final choice here.


---
