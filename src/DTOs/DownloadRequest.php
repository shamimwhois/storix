<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use InvalidArgumentException;

final readonly class DownloadRequest
{
    public function __construct(
        public string $fileId,
        public ?string $tenantId = null,
        public ?string $ownerId = null,
        public ?int $offset = null,
        public ?int $length = null,
    ) {
        if (trim($this->fileId) === '' || strlen($this->fileId) > 64 || str_contains($this->fileId, "\0")) {
            throw new InvalidArgumentException('File ID must be a non-empty identifier of at most 64 bytes.');
        }

        foreach (['tenantId' => $tenantId, 'ownerId' => $ownerId] as $field => $value) {
            if ($value !== null && (trim($value) === '' || strlen($value) > 64 || str_contains($value, "\0"))) {
                throw new InvalidArgumentException("{$field} must be null or a non-empty string of at most 64 bytes.");
            }
        }

        if (($this->offset === null) !== ($this->length === null)) {
            throw new InvalidArgumentException('Range offset and length must be provided together.');
        }

        if ($this->offset !== null && $this->offset < 0) {
            throw new InvalidArgumentException('Range offset cannot be negative.');
        }

        if ($this->length !== null && $this->length < 1) {
            throw new InvalidArgumentException('Range length must be positive.');
        }
    }

    public function isRangeRequest(): bool
    {
        return $this->offset !== null;
    }
}
