<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Contracts;

use ShamimStack\Storix\DTOs\DeleteObjectRequest;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StatObjectRequest;
use ShamimStack\Storix\DTOs\StorageObjectMetadata;
use ShamimStack\Storix\DTOs\WriteResult;
use ShamimStack\Storix\DTOs\WriteStreamRequest;

interface StorageDriver
{
    public function name(): string;

    public function capabilities(): DriverCapabilities;

    public function writeStream(WriteStreamRequest $request): WriteResult;

    /**
     * Return a readable PHP stream resource.
     *
     * @return resource
     */
    public function readStream(ReadStreamRequest $request): mixed;

    public function delete(DeleteObjectRequest $request): void;

    public function exists(string $key): bool;

    public function stat(StatObjectRequest $request): ?StorageObjectMetadata;
}
