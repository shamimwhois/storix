<?php

declare(strict_types=1);

namespace ShamimStack\Storix;

use Illuminate\Support\ServiceProvider;

final class StorixServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/storix.php',
            'storix',
        );
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
