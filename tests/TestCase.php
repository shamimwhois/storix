<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use ShamimStack\Storix\StorixServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [StorixServiceProvider::class];
    }
}
