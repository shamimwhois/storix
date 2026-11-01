<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Exceptions;

use ShamimStack\Storix\Enums\StorixErrorCode;

final class DownloadTooLargeException extends StorixException
{
    public function __construct(public readonly int $maximumBytes)
    {
        parent::__construct(
            "Download exceeds the configured maximum size of {$maximumBytes} bytes.",
            StorixErrorCode::DOWNLOAD_TOO_LARGE,
        );
    }
}
