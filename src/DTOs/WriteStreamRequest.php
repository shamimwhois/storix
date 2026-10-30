<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use InvalidArgumentException;
use ShamimStack\Storix\Enums\ObjectVisibility;

final readonly class WriteStreamRequest
{
    /**
     * @param resource $stream
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public string $key,
        public mixed $stream,
        public ?string $mimeType = null,
        public ObjectVisibility $visibility = ObjectVisibility::PRIVATE,
        public array $metadata = [],
    ) {
        if ($this->key === '' || str_contains($this->key, "\0")) {
            throw new InvalidArgumentException('Storage key must be a non-empty string without null bytes.');
        }

        if (is_resource($this->stream) === false || get_resource_type($this->stream) !== 'stream') {
            throw new InvalidArgumentException('The write source must be a valid stream resource.');
        }
    }
}
