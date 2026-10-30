<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

final readonly class WriteResult
{
    public function __construct(public StorageObjectMetadata $object) {}
}
