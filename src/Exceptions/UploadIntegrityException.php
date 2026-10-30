<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Exceptions;

use ShamimStack\Storix\Enums\StorixErrorCode;

final class UploadIntegrityException extends StorixException
{
    public function __construct()
    {
        parent::__construct(
            'Stored object integrity verification failed.',
            StorixErrorCode::UPLOAD_INTEGRITY_FAILED,
        );
    }
}
