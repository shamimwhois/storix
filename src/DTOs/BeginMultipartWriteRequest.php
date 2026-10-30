<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use InvalidArgumentException;
use ShamimStack\Storix\Enums\ObjectVisibility;

/** @property array<string, scalar|null> $metadata */
final readonly class BeginMultipartWriteRequest
{
    public function __construct(
        public string $key,
        public ?string $mimeType = null,
        public ObjectVisibility $visibility = ObjectVisibility::PRIVATE,
        public array $metadata = [],
    ) {
        if (trim($this->key) === '' || str_contains($this->key, "\0")) {
            throw new InvalidArgumentException('Multipart object key must be non-empty and contain no null bytes.');
        }

        if ($this->mimeType !== null && (
            strlen($this->mimeType) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $this->mimeType) === 1
        )) {
            throw new InvalidArgumentException('Multipart MIME metadata is invalid.');
        }

        foreach ($this->metadata as $key => $value) {
            if (is_string($key) === false || (is_scalar($value) === false && $value !== null)) {
                throw new InvalidArgumentException('Multipart metadata keys must be strings and values must be scalar or null.');
            }
        }
    }
}
