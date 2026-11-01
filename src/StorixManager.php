<?php

declare(strict_types=1);

namespace ShamimStack\Storix;

use LogicException;
use ShamimStack\Storix\Contracts\StorageDriver;
use ShamimStack\Storix\Downloads\DownloadManager;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\Uploads\UploadManager;

final readonly class StorixManager
{
    public function __construct(
        private DriverManager $drivers,
        private ?UploadManager $uploads = null,
        private ?DownloadManager $downloads = null,
    ) {}

    public function driver(?string $name = null): StorageDriver
    {
        return $this->drivers->driver($name);
    }

    public function capabilities(?string $name = null): DriverCapabilities
    {
        return $this->driver($name)->capabilities();
    }

    public function uploads(): UploadManager
    {
        return $this->uploads ?? throw new LogicException('Storix upload services are not bound in the container.');
    }

    public function downloads(): DownloadManager
    {
        return $this->downloads ?? throw new LogicException('Storix download services are not bound in the container.');
    }

    /** @return list<string> */
    public function drivers(): array
    {
        return $this->drivers->names();
    }
}
