<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Contracts;

use ShamimStack\Storix\DTOs\StorageDirectoryEntry;

interface DirectoryDriver
{
    public function makeDirectory(string $key): void;

    /**
     * List immediate children of a directory. An empty key represents the driver root.
     *
     * @return list<StorageDirectoryEntry>
     */
    public function listDirectory(string $key = ''): array;

    public function deleteDirectory(string $key, bool $recursive = false): void;
}
