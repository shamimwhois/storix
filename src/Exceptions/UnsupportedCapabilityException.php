<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Exceptions;

use ShamimStack\Storix\Enums\StorixErrorCode;

final class UnsupportedCapabilityException extends StorixException
{
    public function __construct(string $capability, string $driver)
    {
        parent::__construct(
            "Storage driver [{$driver}] does not support [{$capability}].",
            StorixErrorCode::UNSUPPORTED_CAPABILITY,
        );
    }
}
