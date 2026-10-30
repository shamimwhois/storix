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

    /** @return Collection<int, Transfer> */
    public function expiredBefore(DateTimeInterface $cutoff, int $limit = 100): Collection;
}
