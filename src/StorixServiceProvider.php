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

        $this->app->singleton('storix', function ($app): StorixManager {
            return new StorixManager($app->make(DriverManager::class));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/storix.php' => config_path('storix.php'),
            ], 'storix-config');
        }
    }
}
