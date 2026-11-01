<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use InvalidArgumentException;
use ShamimStack\Storix\Enums\ObjectVisibility;

/**
 * @property list<MultipartPartResult> $parts
 * @property array<string, scalar|null> $metadata
 */
final readonly class CompleteMultipartWriteRequest
{
    public function __construct(
        public string $uploadId,
        public string $key,
        public array $parts,
        public int $expectedSizeBytes,
        public string $expectedSha256,
        public ?string $mimeType = null,
        public ObjectVisibility $visibility = ObjectVisibility::PRIVATE,
        public array $metadata = [],
    ) {
        if ($this->uploadId === '' || strlen($this->uploadId) > 512 || preg_match('/[\x00-\x1F\x7F]/', $this->uploadId) === 1) {
            throw new InvalidArgumentException('Multipart upload identifier is invalid.');
        }

        if (trim($this->key) === '' || str_contains($this->key, "\0")) {
            throw new InvalidArgumentException('Multipart object key must be non-empty and contain no null bytes.');
        }

        if ($this->expectedSizeBytes < 0 || preg_match('/^[a-f0-9]{64}$/iD', $this->expectedSha256) !== 1) {
            throw new InvalidArgumentException('Expected multipart size or SHA-256 is invalid.');
        }

        if (array_is_list($this->parts) === false || count($this->parts) > 10_000) {
            throw new InvalidArgumentException('Multipart parts must be a sequential list of at most 10000 items.');
        }

        foreach ($this->parts as $index => $part) {
            if ($part instanceof MultipartPartResult === false || $part->partNumber !== $index + 1) {
                throw new InvalidArgumentException('Multipart parts must be ordered and numbered consecutively from 1.');
            }
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
