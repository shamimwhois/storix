<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class StorageDirectoryEntry
{
    /**
     * Validate and store the normalized metadata for one immediate directory-listing entry.
     */
    public function __construct(
        public string $name,
        public string $key,
        public bool $isDirectory,
        public int $sizeBytes,
        public ?DateTimeImmutable $lastModified = null,
    ) {
        if ($this->name === '' || $this->key === '' || str_contains($this->key, "\0") || $this->sizeBytes < 0) {
            throw new InvalidArgumentException('Directory entries require a name, key, and non-negative size.');
        }
    }
}
