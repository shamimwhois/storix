<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use InvalidArgumentException;

final readonly class DeleteObjectRequest
{
    public function __construct(public string $key)
    {
        if ($this->key === '' || str_contains($this->key, "\0")) {
            throw new InvalidArgumentException('Storage key must be a non-empty string without null bytes.');
        }
    }
}
