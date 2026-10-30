<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Exceptions;

use ShamimStack\Storix\Enums\StorixErrorCode;

final class UploadTooLargeException extends StorixException
{
    public function __construct(public readonly int $maximumBytes)
    {
        parent::__construct(
            "Upload exceeds the configured maximum size of {$maximumBytes} bytes.",
            StorixErrorCode::UPLOAD_TOO_LARGE,
        );
    }
}
