<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Contracts\Repositories;

use Illuminate\Support\Collection;
use ShamimStack\Storix\Models\Directory;

interface DirectoryRepository
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Directory;

    public function findScoped(string $id, ?string $tenantId, ?string $ownerId): ?Directory;

    public function findChild(?string $parentId, string $name, string $driver, ?string $tenantId, ?string $ownerId): ?Directory;

    /** @return Collection<int, Directory> */
    public function children(?string $parentId, string $driver, ?string $tenantId, ?string $ownerId): Collection;

    public function save(Directory $directory): Directory;

    public function softDelete(Directory $directory): bool;
}
