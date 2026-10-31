<?php

declare(strict_types=1);

namespace ShamimStack\Storix;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;
use ShamimStack\Storix\Contracts\Repositories\ChunkRepository;
use ShamimStack\Storix\Contracts\Repositories\DirectoryRepository;
use ShamimStack\Storix\Contracts\Repositories\StoredFileRepository;
use ShamimStack\Storix\Contracts\Repositories\TransferRepository;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\Drivers\Local\LocalStorageDriver;
use ShamimStack\Storix\Repositories\Eloquent\EloquentChunkRepository;
use ShamimStack\Storix\Repositories\Eloquent\EloquentDirectoryRepository;
use ShamimStack\Storix\Repositories\Eloquent\EloquentStoredFileRepository;
use ShamimStack\Storix\Repositories\Eloquent\EloquentTransferRepository;
use ShamimStack\Storix\Services\ChunkPlanner;
use ShamimStack\Storix\Services\MultipartSessionReconciler;
use ShamimStack\Storix\Services\TransferStateMachine;
use ShamimStack\Storix\Uploads\UploadManager;

final class StorixServiceProvider extends ServiceProvider
{
    /**
     * Merge package configuration and register drivers, upload services and persistence repositories.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/storix.php', 'storix');

        $this->app->singleton(LocalStorageDriver::class, function ($app): LocalStorageDriver {
            $root = (string) $app['config']->get('storix.drivers.local.root', storage_path('app/storix'));

            return new LocalStorageDriver($root);
        });

        $this->app->singleton(DriverManager::class, function ($app): DriverManager {
            $manager = new DriverManager((string) $app['config']->get('storix.default', 'local'));
            $manager->register('local', $app->make(LocalStorageDriver::class));

            return $manager;
        });

        $this->app->singleton(UploadManager::class, function ($app): UploadManager {
            return new UploadManager(
                $app->make(DriverManager::class),
                $app->make(StoredFileRepository::class),
                $app->make(TransferRepository::class),
                $app->make(DirectoryRepository::class),
                $app->make(ChunkRepository::class),
                $app->make(ChunkPlanner::class),
                $app->make(MultipartSessionReconciler::class),
                $app->make(TransferStateMachine::class),
                $app->make(ConfigRepository::class),
            );
        });

        $this->app->bind(DirectoryRepository::class, EloquentDirectoryRepository::class);
        $this->app->bind(StoredFileRepository::class, EloquentStoredFileRepository::class);
        $this->app->bind(TransferRepository::class, EloquentTransferRepository::class);
        $this->app->bind(ChunkRepository::class, EloquentChunkRepository::class);

        $this->app->singleton('storix', function ($app): StorixManager {
            return new StorixManager(
                $app->make(DriverManager::class),
                $app->make(UploadManager::class),
            );
        });
    }

    /**
     * Load package migrations and publish the Storix configuration for console applications.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/storix.php' => config_path('storix.php'),
            ], 'storix-config');
        }
    }
}
