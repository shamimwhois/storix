# 08 — Security Design

## 1. Threat model

Protect against unauthorized file access, IDOR, cross-tenant leakage, path traversal, malicious uploads, token leakage, replayed requests, resource exhaustion, archive bombs, unsafe public links and compromised workers.

## 2. Authentication and authorization

- Reuse host Laravel authentication; do not invent a parallel user system.
- Authorize every read/write/list/resume/delete/share action.
- Apply tenant and owner scopes in repository queries, not only controllers.
- Use policy/gate hooks so host applications can customize authorization.
- Return 404 for inaccessible objects when avoiding existence disclosure is appropriate.

## 3. Input and path safety

- Normalize directory names and reject `..`, null bytes, absolute paths and control characters.
- Generate storage keys independently of user-supplied filenames.
- Validate file size, extension and MIME using content inspection where possible.
- Treat client MIME and filename as untrusted.
- Never execute or render uploaded files.
- Apply archive extraction limits if archive support is later introduced.

## 4. Secrets and encryption

- Keep provider tokens in Laravel config/environment/secret manager.
- Never log credentials or signed URLs.
- For encryption at rest, use authenticated encryption (for example AES-256-GCM or XChaCha20-Poly1305 through a vetted library), unique nonce per encryption operation and versioned key identifiers.
- Support key rotation; do not store raw encryption keys in the database.
- Define whether encryption is per file or per chunk and how streaming decryption works before implementation.

## 5. Sharing

If share links are implemented:
- generate high-entropy random tokens;
- store only token hashes;
- support expiry, revocation and optional download limits/password;
- rate-limit token attempts;
- do not use predictable file IDs as access tokens;
- avoid permanent public URLs by default.

## 6. Resource protection

- Enforce maximum file size, chunk size, active transfers, concurrency and queue attempts.
- Apply per-user/tenant quotas and rate limits.
- Check temporary disk capacity before large operations and monitor during transfer.
- Bound memory usage through streaming.
- Protect against decompression bombs if archive features are added.

## 7. Integrity and audit

- SHA-256 for chunk and final content verification.
- Use constant-time comparison for secret/token hashes where applicable.
- Record state transitions, actor, request ID and sanitized error code.
- Do not include raw user content or credentials in logs.
- Define retention/deletion policy for metadata and transfer history.

## 8. Security release gate

No release until IDOR/tenant isolation, path traversal, token handling, secret redaction, upload validation, rate limits, retry abuse and temporary-file cleanup tests pass.


---
