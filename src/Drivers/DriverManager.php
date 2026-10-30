<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Drivers;

use InvalidArgumentException;
use ShamimStack\Storix\Contracts\StorageDriver;
use ShamimStack\Storix\Exceptions\DriverNotFoundException;

final class DriverManager
{
    /** @var array<string, StorageDriver> */
    private array $drivers = [];

    public function __construct(private readonly string $defaultDriver = 'local') {}

    public function register(string $name, StorageDriver $driver): void
    {
        $normalized = trim($name);

        if ($normalized === '' || $normalized !== $driver->name()) {
            throw new InvalidArgumentException('Driver registration name must match the driver name.');
        }

        $this->drivers[$normalized] = $driver;
    }

    public function driver(?string $name = null): StorageDriver
    {
        $resolved = $name ?? $this->defaultDriver;

        return $this->drivers[$resolved] ?? throw new DriverNotFoundException($resolved);
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = array_map('strval', array_keys($this->drivers));
        sort($names);

        return $names;
    }
}
