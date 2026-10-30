<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Contracts;

use ShamimStack\Storix\DTOs\StorageDirectoryEntry;

interface DirectoryDriver
{
    /**
     * Create a directory, including missing parents, using a driver-relative key.
     */
    public function makeDirectory(string $key): void;

    /**
     * List immediate children of a directory. An empty key represents the driver root.
     *
     * @return list<StorageDirectoryEntry>
     */
    public function listDirectory(string $key = ''): array;

    /**
     * Delete a directory, with recursive removal disabled unless explicitly requested.
     */
    public function deleteDirectory(string $key, bool $recursive = false): void;
}
