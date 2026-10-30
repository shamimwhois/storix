<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShamimStack\Storix\Contracts\StorageDriver;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\Exceptions\DriverNotFoundException;

final class DriverManagerTest extends TestCase
{
    public function test_it_registers_and_resolves_drivers(): void
    {
        $manager = new DriverManager('memory');
        $driver = $this->createMock(StorageDriver::class);
        $driver->method('name')->willReturn('memory');
        $driver->method('capabilities')->willReturn(new DriverCapabilities());

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
