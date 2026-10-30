<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Repositories\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use ShamimStack\Storix\Contracts\Repositories\StoredFileRepository;
use ShamimStack\Storix\Models\StoredFile;

final class EloquentStoredFileRepository implements StoredFileRepository
{
    public function create(array $attributes): StoredFile
    {
        return StoredFile::query()->create($attributes);
    }

    public function findScoped(string $id, ?string $tenantId, ?string $ownerId): ?StoredFile
    {
        return $this->applyScope(StoredFile::query(), $tenantId, $ownerId)->find($id);
    }

    public function findByFingerprint(string $sha256, int $sizeBytes, string $driver, ?string $directoryId, ?string $tenantId, ?string $ownerId): ?StoredFile
    {
        $query = $this->applyScope(StoredFile::query(), $tenantId, $ownerId)
            ->where('sha256', strtolower($sha256))
            ->where('size_bytes', $sizeBytes)
            ->where('driver', $driver);

        $directoryId === null
            ? $query->whereNull('directory_id')
            : $query->where('directory_id', $directoryId);

        return $query->first();
    }

    public function save(StoredFile $file): StoredFile
    {
        $file->save();

        return $file->refresh();
    }

    public function softDelete(StoredFile $file): bool
    {
        return (bool) $file->delete();
    }

    /** @param Builder<StoredFile> $query
     *  @return Builder<StoredFile>
     */
    private function applyScope(Builder $query, ?string $tenantId, ?string $ownerId): Builder
    {
        $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
        $ownerId === null ? $query->whereNull('owner_id') : $query->where('owner_id', $ownerId);

        return $query;
    }
}
