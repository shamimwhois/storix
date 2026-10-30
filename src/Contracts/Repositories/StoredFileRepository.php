<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Contracts\Repositories;

use ShamimStack\Storix\Models\StoredFile;

interface StoredFileRepository
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): StoredFile;

    public function findScoped(string $id, ?string $tenantId, ?string $ownerId): ?StoredFile;

    public function findByFingerprint(string $sha256, int $sizeBytes, string $driver, ?string $directoryId, ?string $tenantId, ?string $ownerId): ?StoredFile;

    public function save(StoredFile $file): StoredFile;

    public function softDelete(StoredFile $file): bool;
}
