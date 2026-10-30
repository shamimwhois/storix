<?php

declare(strict_types=1);

namespace ShamimStack\Storix;

use ShamimStack\Storix\Contracts\StorageDriver;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\DTOs\DriverCapabilities;

final readonly class StorixManager
{
    public function __construct(private DriverManager $drivers) {}

    public function driver(?string $name = null): StorageDriver
    {
        return $this->drivers->driver($name);
    }

    public function capabilities(?string $name = null): DriverCapabilities
    {
        return $this->driver($name)->capabilities();
    }

    /** @return list<string> */
    public function drivers(): array
    {
        return $this->drivers->names();
    }
}
