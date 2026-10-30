<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Exceptions;

use ShamimStack\Storix\Enums\StorixErrorCode;

final class UploadInProgressException extends StorixException
{
    public function __construct()
    {
        parent::__construct(
            'An upload with this idempotency key is already in progress.',
            StorixErrorCode::UPLOAD_IN_PROGRESS,
        );
    }
}
