<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ShamimStack\Storix\DTOs\DriverCapabilities;

final class DriverCapabilitiesTest extends TestCase
{
    public function test_it_exposes_normalized_capability_data(): void
    {
        $capabilities = new DriverCapabilities(
            maxObjectSizeBytes: 1_000_000,
            minChunkSizeBytes: 1_024,
            preferredChunkSizeBytes: 4_096,
            maxChunkSizeBytes: 8_192,
            maxConcurrency: 4,
            supportsRangeReads: true,
        );

        self::assertSame(4, $capabilities->toArray()['max_concurrency']);
        self::assertTrue($capabilities->toArray()['supports_range_reads']);
    }

    public function test_it_rejects_inconsistent_chunk_limits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DriverCapabilities(
            minChunkSizeBytes: 8_192,
            preferredChunkSizeBytes: 4_096,
        );
    }
}
