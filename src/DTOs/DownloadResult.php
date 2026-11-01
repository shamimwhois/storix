<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use InvalidArgumentException;
use ShamimStack\Storix\Models\StoredFile;

final readonly class DownloadResult
{
    /** @var resource */
    public mixed $stream;

    public function __construct(
        public StoredFile $file,
        mixed $stream,
        public int $contentLength,
        public int $rangeStart,
        public bool $partial,
        public bool $checksumVerified,
    ) {
        if (is_resource($stream) === false || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('Download result requires a readable stream resource.');
        }

        $mode = stream_get_meta_data($stream)['mode'] ?? '';

        if (str_contains($mode, 'r') === false && str_contains($mode, '+') === false) {
            throw new InvalidArgumentException('Download result stream must be readable.');
        }

        $this->stream = $stream;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
