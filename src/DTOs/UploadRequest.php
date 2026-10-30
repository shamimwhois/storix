<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use InvalidArgumentException;
use ShamimStack\Storix\Enums\ObjectVisibility;

final readonly class UploadRequest
{
    /** @param resource $stream
     *  @param array<string, scalar|null> $metadata
     */
    public mixed $stream;

    public string $originalName;

    public ?string $tenantId;

    public ?string $ownerId;

    public ?string $directoryId;

    public ?string $driver;

    public ?string $mimeType;

    public ObjectVisibility $visibility;

    public ?int $expectedSizeBytes;

    public ?string $idempotencyKey;

    public array $metadata;

    public function __construct(
        mixed $stream,
        string $originalName,
        ?string $tenantId = null,
        ?string $ownerId = null,
        ?string $directoryId = null,
        ?string $driver = null,
        ?string $mimeType = null,
        ObjectVisibility $visibility = ObjectVisibility::PRIVATE,
        ?int $expectedSizeBytes = null,
        ?string $idempotencyKey = null,
        array $metadata = [],
    ) {
        if (is_resource($stream) === false || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Upload source must be a valid stream resource.');
        }

        $mode = stream_get_meta_data($stream)['mode'] ?? '';

        if (str_contains($mode, 'r') === false && str_contains($mode, '+') === false) {
            throw new InvalidArgumentException('Upload source stream must be readable.');
        }

        $normalizedName = trim($originalName);

        if (
            $normalizedName === ''
            || $normalizedName === '.'
            || $normalizedName === '..'
            || strlen($normalizedName) > 255
            || preg_match('//u', $normalizedName) !== 1
            || preg_match('/[\/\\\x00-\x1F\x7F]/u', $normalizedName) === 1
        ) {
            throw new InvalidArgumentException('Original filename is invalid.');
        }

        foreach (['tenantId' => $tenantId, 'ownerId' => $ownerId] as $field => $value) {
            if ($value !== null && (trim($value) === '' || strlen($value) > 64 || str_contains($value, "\0"))) {
                throw new InvalidArgumentException("{$field} must be null or a non-empty string of at most 64 bytes.");
            }
        }

        if ($directoryId !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $directoryId) !== 1) {
            throw new InvalidArgumentException('Directory ID must be a valid UUID.');
        }

        if ($driver !== null && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', trim($driver)) !== 1) {
            throw new InvalidArgumentException('Driver name is invalid.');
        }

        $normalizedMime = $mimeType === null ? null : trim($mimeType);

        if ($normalizedMime !== null && (
            strlen($normalizedMime) > 255
            || preg_match('/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/D', $normalizedMime) !== 1
        )) {
            throw new InvalidArgumentException('MIME type is invalid.');
        }

        if ($expectedSizeBytes !== null && $expectedSizeBytes < 0) {
            throw new InvalidArgumentException('Expected size cannot be negative.');
        }

        $normalizedKey = $idempotencyKey === null ? null : trim($idempotencyKey);

        if ($normalizedKey !== null && ($normalizedKey === '' || strlen($normalizedKey) > 128)) {
            throw new InvalidArgumentException('Idempotency key must contain between 1 and 128 bytes.');
        }

        foreach ($metadata as $key => $value) {
            if (is_string($key) === false || (is_scalar($value) === false && $value !== null)) {
                throw new InvalidArgumentException('Upload metadata keys must be strings and values must be scalar or null.');
            }
        }

        $this->stream = $stream;
        $this->originalName = $normalizedName;
        $this->tenantId = $tenantId === null ? null : trim($tenantId);
        $this->ownerId = $ownerId === null ? null : trim($ownerId);
        $this->directoryId = $directoryId;
        $this->driver = $driver === null ? null : trim($driver);
        $this->mimeType = $normalizedMime;
        $this->visibility = $visibility;
        $this->expectedSizeBytes = $expectedSizeBytes;
        $this->idempotencyKey = $normalizedKey;
        $this->metadata = $metadata;
    }
}
