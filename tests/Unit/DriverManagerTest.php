<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShamimStack\Storix\Contracts\StorageDriver;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\DTOs\DeleteObjectRequest;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StatObjectRequest;
use ShamimStack\Storix\DTOs\StorageObjectMetadata;
use ShamimStack\Storix\DTOs\WriteResult;
use ShamimStack\Storix\DTOs\WriteStreamRequest;
use ShamimStack\Storix\Exceptions\DriverNotFoundException;

final class DriverManagerTest extends TestCase
{
    public function test_it_registers_and_resolves_drivers(): void
    {
        $manager = new DriverManager('memory');
        $driver = new class implements StorageDriver {
            public function name(): string { return 'memory'; }
            public function capabilities(): DriverCapabilities { return new DriverCapabilities(); }
            public function writeStream(WriteStreamRequest $request): WriteResult { throw new \LogicException(); }
            public function readStream(ReadStreamRequest $request): mixed { throw new \LogicException(); }
            public function delete(DeleteObjectRequest $request): void {}
            public function exists(string $key): bool { return false; }
            public function stat(StatObjectRequest $request): ?StorageObjectMetadata { return null; }
        };

        $manager->register('memory', $driver);

        self::assertSame($driver, $manager->driver());
        self::assertSame(['memory'], $manager->names());
    }

    public function test_it_throws_a_stable_exception_for_unknown_drivers(): void
    {
        $this->expectException(DriverNotFoundException::class);

        (new DriverManager())->driver('missing');
    }
}
