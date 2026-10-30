<?php

declare(strict_types=1);

namespace ShamimStack\Storix;

use Illuminate\Support\ServiceProvider;
use ShamimStack\Storix\Drivers\DriverManager;

final class StorixServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/storix.php', 'storix');

        $this->app->singleton(DriverManager::class, function ($app): DriverManager {
            return new DriverManager((string) $app['config']->get('storix.default', 'local'));
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
