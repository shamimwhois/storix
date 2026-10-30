<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use InvalidArgumentException;

final readonly class DriverCapabilities
{
    public function __construct(
        public ?int $maxObjectSizeBytes = null,
        public int $minChunkSizeBytes = 1,
        public int $preferredChunkSizeBytes = 8_388_608,
        public int $maxChunkSizeBytes = 67_108_864,
        public int $maxConcurrency = 1,
        public bool $supportsStreaming = true,
        public bool $supportsRangeReads = false,
        public bool $supportsMultipartWrites = false,
        public bool $supportsServerSideCopy = false,
        public bool $supportsAtomicFinalize = false,
        public bool $supportsPauseResume = false,
    ) {
        if ($this->maxObjectSizeBytes !== null && $this->maxObjectSizeBytes < 1) {
            throw new InvalidArgumentException('Maximum object size must be positive.');
        }

        if ($this->minChunkSizeBytes < 1
            || $this->preferredChunkSizeBytes < $this->minChunkSizeBytes
            || $this->maxChunkSizeBytes < $this->preferredChunkSizeBytes
            || $this->maxConcurrency < 1) {
            throw new InvalidArgumentException('Driver capability limits are inconsistent.');
        }
    }

    /** @return array<string, int|bool|null> */
    public function toArray(): array
    {
        return [
            'max_object_size_bytes' => $this->maxObjectSizeBytes,
            'min_chunk_size_bytes' => $this->minChunkSizeBytes,
            'preferred_chunk_size_bytes' => $this->preferredChunkSizeBytes,
            'max_chunk_size_bytes' => $this->maxChunkSizeBytes,
            'max_concurrency' => $this->maxConcurrency,
            'supports_streaming' => $this->supportsStreaming,
            'supports_range_reads' => $this->supportsRangeReads,
            'supports_multipart_writes' => $this->supportsMultipartWrites,
            'supports_server_side_copy' => $this->supportsServerSideCopy,
            'supports_atomic_finalize' => $this->supportsAtomicFinalize,
            'supports_pause_resume' => $this->supportsPauseResume,
        ];
    }
}
