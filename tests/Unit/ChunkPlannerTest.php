<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\Services\ChunkPlanner;

final class ChunkPlannerTest extends TestCase
{
    public function test_it_returns_no_chunks_for_an_empty_transfer(): void
    {
        self::assertSame([], (new ChunkPlanner)->plan(0, new DriverCapabilities));
    }

    public function test_it_builds_deterministic_offsets_and_sizes(): void
    {
        $plan = (new ChunkPlanner)->plan(10, new DriverCapabilities, 4);

        self::assertSame([0, 4, 8], array_map(static fn ($chunk): int => $chunk->offsetBytes, $plan));
        self::assertSame([4, 4, 2], array_map(static fn ($chunk): int => $chunk->sizeBytes, $plan));
        self::assertSame([0, 1, 2], array_map(static fn ($chunk): int => $chunk->index, $plan));
    }

    public function test_it_clamps_requested_size_to_driver_capabilities(): void
    {
        $capabilities = new DriverCapabilities(
            minChunkSizeBytes: 4,
            preferredChunkSizeBytes: 8,
            maxChunkSizeBytes: 16,
        );

        $plan = (new ChunkPlanner)->plan(35, $capabilities, 100);

        self::assertSame([16, 16, 3], array_map(static fn ($chunk): int => $chunk->sizeBytes, $plan));
    }

    public function test_it_rejects_a_non_positive_requested_chunk_size(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ChunkPlanner)->plan(20, new DriverCapabilities, 0);
    }

    public function test_it_rejects_more_than_ten_thousand_parts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ChunkPlanner)->plan(10_001, new DriverCapabilities, 1);
    }
}
