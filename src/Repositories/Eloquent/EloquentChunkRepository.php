<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Repositories\Eloquent;

use Illuminate\Support\Collection;
use ShamimStack\Storix\Contracts\Repositories\ChunkRepository;
use ShamimStack\Storix\Models\Chunk;

final class EloquentChunkRepository implements ChunkRepository
{
    public function create(array $attributes): Chunk
    {
        return Chunk::query()->create($attributes);
    }

    public function findByIndex(string $transferId, int $chunkIndex): ?Chunk
    {
        return Chunk::query()
            ->where('transfer_id', $transferId)
            ->where('chunk_index', $chunkIndex)
            ->first();
    }

    public function forTransfer(string $transferId): Collection
    {
        return Chunk::query()
            ->where('transfer_id', $transferId)
            ->orderBy('chunk_index')
            ->get();
    }

    public function save(Chunk $chunk): Chunk
    {
        $chunk->save();

        return $chunk->refresh();
    }

    public function deleteForTransfer(string $transferId): int
    {
        return Chunk::query()->where('transfer_id', $transferId)->delete();
    }
}
