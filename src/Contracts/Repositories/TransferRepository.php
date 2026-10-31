<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Contracts\Repositories;

use DateTimeInterface;
use Illuminate\Support\Collection;
use ShamimStack\Storix\Models\Transfer;

interface TransferRepository
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Transfer;

    public function findScoped(string $id, ?string $tenantId, ?string $ownerId): ?Transfer;

    public function findByIdempotencyKey(string $key, ?string $tenantId, ?string $ownerId): ?Transfer;

    /** @param array<string, mixed> $attributes */
    public function firstOrCreateIdempotent(array $attributes): Transfer;

    public function save(Transfer $transfer): Transfer;

    /**
     * Atomically claim an active transfer for one resume worker.
     *
     * An unexpired lease owned by another token cannot be replaced.
     */
    public function claimResumeLease(
        string $transferId,
        string $token,
        DateTimeInterface $now,
        DateTimeInterface $expiresAt,
        ?DateTimeInterface $claimExpiredBefore = null,
    ): bool;

    /**
     * Lock the transfer row and confirm the token still owns an unexpired lease.
     *
     * Call inside the transaction that commits file availability and transfer completion.
     */
    public function ownsResumeLease(
        string $transferId,
        string $token,
        DateTimeInterface $now,
    ): bool;

    /** Mark a possible late provider publication for a later cleanup sweep. */
    public function markCleanupPending(string $transferId, DateTimeInterface $now): bool;

    /** Extend an unexpired lease only while the caller still owns its token. */
    public function renewResumeLease(
        string $transferId,
        string $token,
        DateTimeInterface $now,
        DateTimeInterface $expiresAt,
    ): bool;

    /** Release a lease only when the caller still owns its token. */
    public function releaseResumeLease(string $transferId, string $token): bool;

    /** @return Collection<int, Transfer> */
    public function expiredBefore(DateTimeInterface $cutoff, int $limit = 100): Collection;
}
