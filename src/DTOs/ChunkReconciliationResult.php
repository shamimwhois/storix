<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use ShamimStack\Storix\Models\Chunk;

final readonly class ChunkReconciliationResult
{
    /**
     * @param  list<Chunk>  $reusableChunks
     * @param  list<Chunk>  $invalidatedChunks
     */
    public function __construct(
        public array $reusableChunks,
        public array $invalidatedChunks,
        public int $verifiedBytes,
        /** @var list<MultipartPartResult> */
        public array $reusableParts = [],
    ) {}

    public function completedChunks(): int
    {
        return count($this->reusableChunks);
    }
}
