<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Exceptions;

use Throwable;
use ShamimStack\Storix\Enums\StorixErrorCode;

final class StorageOperationFailedException extends StorixException
{
    public function __construct(string $operation, ?Throwable $previous = null)
    {
        parent::__construct(
            "The storage operation [{$operation}] failed.",
            StorixErrorCode::STORAGE_OPERATION_FAILED,
            $previous,
        );
    }
}
