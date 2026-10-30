<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use ShamimStack\Storix\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_package_configuration_is_merged_into_the_application(): void
    {
        $this->assertSame('local', config('storix.default'));
        $this->assertSame(8 * 1024 * 1024, config('storix.transfers.chunk_size'));
        $this->assertSame(3, config('storix.transfers.max_parallel_chunks'));
    }
}
