<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Exceptions;

use ShamimStack\Storix\Enums\StorixErrorCode;

final class DriverNotFoundException extends StorixException
{
    public function __construct(string $driver)
    {
        parent::__construct(
            "Storix storage driver [{$driver}] is not registered.",
            StorixErrorCode::DRIVER_NOT_FOUND,
        );
    }
}
