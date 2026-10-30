<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use InvalidArgumentException;

final readonly class ReadStreamRequest
{
    public function __construct(
        public string $key,
        public ?int $offset = null,
        public ?int $length = null,
    ) {
        if ($this->key === '' || str_contains($this->key, "\0")) {
            throw new InvalidArgumentException('Storage key must be a non-empty string without null bytes.');
        }

        if ($this->offset !== null && $this->offset < 0) {
            throw new InvalidArgumentException('Read offset cannot be negative.');
        }

        if ($this->length !== null && $this->length < 1) {
            throw new InvalidArgumentException('Read length must be positive.');
        }
    }
}
