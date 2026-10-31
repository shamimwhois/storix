<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use InvalidArgumentException;

final readonly class MultipartPartResult
{
    public function __construct(
        public int $partNumber,
        public int $sizeBytes,
        public string $sha256,
        public ?string $driverReference = null,
    ) {
        if ($this->partNumber < 1 || $this->partNumber > 10_000 || $this->sizeBytes < 0) {
            throw new InvalidArgumentException('Multipart part result dimensions are invalid.');
        }

        if (preg_match('/^[a-f0-9]{64}$/iD', $this->sha256) !== 1) {
            throw new InvalidArgumentException('Multipart part SHA-256 must be a 64-character hexadecimal digest.');
        }

        if ($this->driverReference !== null && (
            strlen($this->driverReference) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $this->driverReference) === 1
        )) {
            throw new InvalidArgumentException('Multipart driver reference is invalid.');
        }
    }
}
