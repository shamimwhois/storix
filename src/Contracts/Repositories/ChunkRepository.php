<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Contracts\Repositories;

use Illuminate\Support\Collection;
use ShamimStack\Storix\Models\Chunk;

interface ChunkRepository
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Chunk;

    public function findByIndex(string $transferId, int $chunkIndex): ?Chunk;

    /** @return Collection<int, Chunk> */
    public function forTransfer(string $transferId): Collection;

    public function save(Chunk $chunk): Chunk;

    public function deleteForTransfer(string $transferId): int;
}
