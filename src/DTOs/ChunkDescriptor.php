<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use InvalidArgumentException;

final readonly class ChunkDescriptor
{
    public function __construct(
        public int $index,
        public int $offsetBytes,
        public int $sizeBytes,
    ) {
        if ($this->index < 0 || $this->offsetBytes < 0 || $this->sizeBytes < 1) {
            throw new InvalidArgumentException('Chunk index, offset and size are invalid.');
        }
    }
}
