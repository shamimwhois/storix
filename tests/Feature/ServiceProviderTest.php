<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\StorixManager;
use ShamimStack\Storix\Tests\TestCase;
use ShamimStack\Storix\Uploads\UploadManager;

final class ServiceProviderTest extends TestCase
{
    public function test_package_configuration_is_merged_into_the_application(): void
    {
        $this->assertSame('local', config('storix.default'));
        $this->assertSame(8 * 1024 * 1024, config('storix.transfers.chunk_size'));
        $this->assertSame(3, config('storix.transfers.max_parallel_chunks'));
        $this->assertSame(64 * 1024 * 1024, config('storix.uploads.max_file_size_bytes'));
        $this->assertSame(8 * 1024 * 1024, config('storix.uploads.spool_memory_bytes'));
    }

    public function test_manager_services_are_registered_as_singletons(): void
    {
        fwrite(STDERR, 'SP-DIAG-1'.PHP_EOL);
        $this->assertInstanceOf(DriverManager::class, $this->app->make(DriverManager::class));
        fwrite(STDERR, 'SP-DIAG-2'.PHP_EOL);
        $this->assertInstanceOf(UploadManager::class, $this->app->make(UploadManager::class));
        fwrite(STDERR, 'SP-DIAG-3'.PHP_EOL);
        $this->assertInstanceOf(StorixManager::class, $this->app->make('storix'));
        fwrite(STDERR, 'SP-DIAG-4'.PHP_EOL);
        $this->assertSame($this->app->make('storix'), $this->app->make('storix'));
        fwrite(STDERR, 'SP-DIAG-5'.PHP_EOL);
    }
}
