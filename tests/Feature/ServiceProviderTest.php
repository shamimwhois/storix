<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ShamimStack\Storix\Contracts\Repositories\ChunkRepository;
use ShamimStack\Storix\Contracts\Repositories\DirectoryRepository;
use ShamimStack\Storix\Contracts\Repositories\StoredFileRepository;
use ShamimStack\Storix\Contracts\Repositories\TransferRepository;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\Services\ChunkPlanner;
use ShamimStack\Storix\Services\MultipartSessionReconciler;
use ShamimStack\Storix\Services\TransferStateMachine;
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
        $this->assertInstanceOf(DriverManager::class, $this->app->make(DriverManager::class));
        $dependencies = [
            StoredFileRepository::class,
            TransferRepository::class,
            DirectoryRepository::class,
            ChunkRepository::class,
            ChunkPlanner::class,
            MultipartSessionReconciler::class,
            TransferStateMachine::class,
            ConfigRepository::class,
        ];
        foreach ($dependencies as $index => $dependency) {
            fwrite(STDERR, 'DEP-'.($index + 1).'-BEFORE'.PHP_EOL);
            $this->app->make($dependency);
            fwrite(STDERR, 'DEP-'.($index + 1).'-AFTER'.PHP_EOL);
        }
        fwrite(STDERR, 'UP-1'.PHP_EOL);
        $this->assertInstanceOf(UploadManager::class, $this->app->make(UploadManager::class));
        fwrite(STDERR, 'UP-2'.PHP_EOL);
        fwrite(STDERR, 'UP-3'.PHP_EOL);
        $this->assertInstanceOf(StorixManager::class, $this->app->make('storix'));
        fwrite(STDERR, 'UP-4'.PHP_EOL);
        $this->assertSame($this->app->make('storix'), $this->app->make('storix'));
    }
}
