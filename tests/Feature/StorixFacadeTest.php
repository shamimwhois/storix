<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use ShamimStack\Storix\Contracts\StorageDriver;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\Facades\Storix;
use ShamimStack\Storix\StorixManager;
use ShamimStack\Storix\Tests\TestCase;
use ShamimStack\Storix\Uploads\UploadManager;

final class StorixFacadeTest extends TestCase
{
    public function test_facade_resolves_a_registered_driver(): void
    {
        $driver = $this->createMock(StorageDriver::class);
        $driver->method('name')->willReturn('memory');
        $driver->method('capabilities')->willReturn(new DriverCapabilities(supportsRangeReads: true));

        $manager = new DriverManager('memory');
        $manager->register('memory', $driver);
        $this->app->instance('storix', new StorixManager($manager));

        self::assertSame($driver, Storix::driver());
        self::assertTrue(Storix::capabilities()->supportsRangeReads);
    }

    public function test_facade_exposes_the_upload_manager(): void
    {
        self::assertInstanceOf(UploadManager::class, Storix::uploads());
    }
}
