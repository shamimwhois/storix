<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Exceptions;

use ShamimStack\Storix\Enums\StorixErrorCode;
use Throwable;

final class StorageOperationFailedException extends StorixException
{
    /**
     * Create a sanitized storage-operation exception while retaining the original cause.
     */
    public function __construct(string $operation, ?Throwable $previous = null)
    {
        parent::__construct(
            "The storage operation [{$operation}] failed.",
            StorixErrorCode::STORAGE_OPERATION_FAILED,
            $previous,
        );
    }
}
