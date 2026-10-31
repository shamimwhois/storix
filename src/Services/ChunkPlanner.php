<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Services;

use InvalidArgumentException;
use ShamimStack\Storix\DTOs\ChunkDescriptor;
use ShamimStack\Storix\DTOs\DriverCapabilities;

final class ChunkPlanner
{
    /**
     * @return list<ChunkDescriptor>
     */
    public function plan(
        int $totalBytes,
        DriverCapabilities $capabilities,
        ?int $configuredChunkSizeBytes = null,
    ): array {
        if ($totalBytes < 0) {
            throw new InvalidArgumentException('Total transfer size cannot be negative.');
        }

        if ($totalBytes === 0) {
            return [];
        }

        $requestedSize = $configuredChunkSizeBytes ?? $capabilities->preferredChunkSizeBytes;

        if ($requestedSize < 1) {
            throw new InvalidArgumentException('Configured chunk size must be positive.');
        }

        $chunkSize = max(
            $capabilities->minChunkSizeBytes,
            min($requestedSize, $capabilities->maxChunkSizeBytes),
        );

        $chunkCount = intdiv($totalBytes - 1, $chunkSize) + 1;

        if ($chunkCount > 10_000) {
            throw new InvalidArgumentException('The transfer exceeds the 10000-part multipart limit.');
        }

        $chunks = [];
        $offset = 0;

        for ($index = 0; $index < $chunkCount; $index++) {
            $size = min($chunkSize, $totalBytes - $offset);
            $chunks[] = new ChunkDescriptor($index, $offset, $size);
            $offset += $size;
        }

        return $chunks;
    }
}
