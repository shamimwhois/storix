<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Exceptions;

use RuntimeException;
use ShamimStack\Storix\Enums\StorixErrorCode;
use Throwable;

class StorixException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly StorixErrorCode $errorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
