<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use DateTimeImmutable;
use InvalidArgumentException;
use ShamimStack\Storix\Enums\ObjectVisibility;

final readonly class StorageObjectMetadata
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public string $key,
        public int $sizeBytes,
        public ?string $mimeType = null,
        public ?string $checksumSha256 = null,
        public ?DateTimeImmutable $lastModified = null,
        public ObjectVisibility $visibility = ObjectVisibility::PRIVATE,
        public array $metadata = [],
    ) {
        if ($this->key === '' || str_contains($this->key, "\0") || $this->sizeBytes < 0) {
            throw new InvalidArgumentException('Object metadata requires a non-empty key without null bytes and a non-negative size.');
        }

        foreach ($this->metadata as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('Object metadata keys must be strings and values must be scalar or null.');
            }
        }

        if ($this->checksumSha256 !== null && ! preg_match('/^[a-f0-9]{64}$/i', $this->checksumSha256)) {
            throw new InvalidArgumentException('SHA-256 checksum must contain 64 hexadecimal characters.');
        }
    }
}
