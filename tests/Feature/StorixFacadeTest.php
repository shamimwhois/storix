<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use ShamimStack\Storix\Contracts\StorageDriver;
use ShamimStack\Storix\DTOs\DeleteObjectRequest;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StatObjectRequest;
use ShamimStack\Storix\DTOs\StorageObjectMetadata;
use ShamimStack\Storix\DTOs\WriteResult;
use ShamimStack\Storix\DTOs\WriteStreamRequest;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\Facades\Storix;
use ShamimStack\Storix\StorixManager;
use ShamimStack\Storix\Tests\TestCase;

final class StorixFacadeTest extends TestCase
{
    public function test_facade_resolves_a_registered_driver(): void
    {
        $driver = new class implements StorageDriver {
            public function name(): string { return 'memory'; }
            public function capabilities(): DriverCapabilities { return new DriverCapabilities(supportsRangeReads: true); }
            public function writeStream(WriteStreamRequest $request): WriteResult { throw new \LogicException(); }
            public function readStream(ReadStreamRequest $request): mixed { throw new \LogicException(); }
            public function delete(DeleteObjectRequest $request): void {}
            public function exists(string $key): bool { return false; }
            public function stat(StatObjectRequest $request): ?StorageObjectMetadata { return null; }
        };

        $manager = new DriverManager('memory');
        $manager->register('memory', $driver);
        $this->app->instance('storix', new StorixManager($manager));

        self::assertSame($driver, Storix::driver());
        self::assertTrue(Storix::capabilities()->supportsRangeReads);
    }
}
