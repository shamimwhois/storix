<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Exceptions;

use ShamimStack\Storix\Enums\StorixErrorCode;

final class IdempotencyConflictException extends StorixException
{
    public function __construct()
    {
        parent::__construct(
            'The idempotency key is already associated with a different or unsuccessful upload.',
            StorixErrorCode::IDEMPOTENCY_CONFLICT,
        );
    }
}
