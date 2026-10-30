<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Exceptions;

use ShamimStack\Storix\Enums\StorixErrorCode;

final class UploadValidationException extends StorixException
{
    public function __construct(string $message)
    {
        parent::__construct($message, StorixErrorCode::INVALID_REQUEST);
    }
}
