<?php

declare(strict_types=1);

namespace ShamimStack\Storix;

use Illuminate\Support\ServiceProvider;
use ShamimStack\Storix\Contracts\Repositories\ChunkRepository;
use ShamimStack\Storix\Contracts\Repositories\DirectoryRepository;
use ShamimStack\Storix\Contracts\Repositories\StoredFileRepository;
use ShamimStack\Storix\Contracts\Repositories\TransferRepository;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\Repositories\Eloquent\EloquentChunkRepository;
use ShamimStack\Storix\Repositories\Eloquent\EloquentDirectoryRepository;
use ShamimStack\Storix\Repositories\Eloquent\EloquentStoredFileRepository;
use ShamimStack\Storix\Repositories\Eloquent\EloquentTransferRepository;

final class StorixServiceProvider extends ServiceProvider
{
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

        $this->app->bind(DirectoryRepository::class, EloquentDirectoryRepository::class);
        $this->app->bind(StoredFileRepository::class, EloquentStoredFileRepository::class);
        $this->app->bind(TransferRepository::class, EloquentTransferRepository::class);
        $this->app->bind(ChunkRepository::class, EloquentChunkRepository::class);

        $this->app->singleton('storix', function ($app): StorixManager {
            return new StorixManager($app->make(DriverManager::class));
        });
    }

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
