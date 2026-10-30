<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Repositories\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use ShamimStack\Storix\Contracts\Repositories\DirectoryRepository;
use ShamimStack\Storix\Models\Directory;

final class EloquentDirectoryRepository implements DirectoryRepository
{
    public function create(array $attributes): Directory
    {
        return Directory::query()->create($attributes);
    }

    public function findScoped(string $id, ?string $tenantId, ?string $ownerId): ?Directory
    {
        return $this->applyScope(Directory::query(), $tenantId, $ownerId)->find($id);
    }

    public function children(?string $parentId, string $driver, ?string $tenantId, ?string $ownerId): Collection
    {
        $query = $this->applyScope(Directory::query(), $tenantId, $ownerId)
            ->where('driver', $driver);

        $parentId === null
            ? $query->whereNull('parent_id')
            : $query->where('parent_id', $parentId);

        return $query->orderBy('name')->get();
    }

    public function save(Directory $directory): Directory
    {
        $directory->save();

        return $directory->refresh();
    }

    public function softDelete(Directory $directory): bool
    {
        return (bool) $directory->delete();
    }

    /** @param Builder<Directory> $query
     *  @return Builder<Directory>
     */
    private function applyScope(Builder $query, ?string $tenantId, ?string $ownerId): Builder
    {
        $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
        $ownerId === null ? $query->whereNull('owner_id') : $query->where('owner_id', $ownerId);

        return $query;
    }
}
