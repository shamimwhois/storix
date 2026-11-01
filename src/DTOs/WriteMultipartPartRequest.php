<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use InvalidArgumentException;

final readonly class WriteMultipartPartRequest
{
    /** @param resource $stream */
    public function __construct(
        public string $uploadId,
        public int $partNumber,
        public mixed $stream,
    ) {
        if ($this->uploadId === '' || strlen($this->uploadId) > 512 || preg_match('/[\x00-\x1F\x7F]/', $this->uploadId) === 1) {
            throw new InvalidArgumentException('Multipart upload identifier is invalid.');
        }

        if ($this->partNumber < 1 || $this->partNumber > 10_000) {
            throw new InvalidArgumentException('Multipart part number must be between 1 and 10000.');
        }

        if (is_resource($this->stream) === false || get_resource_type($this->stream) !== 'stream') {
            throw new InvalidArgumentException('Multipart part source must be a valid stream resource.');
        }

        $mode = stream_get_meta_data($this->stream)['mode'] ?? '';

        if (str_contains($mode, 'r') === false && str_contains($mode, '+') === false) {
            throw new InvalidArgumentException('Multipart part source must be readable.');
        }
    }
}
