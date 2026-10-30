<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Enums;

enum StorixErrorCode: string
{
    case DRIVER_NOT_FOUND = 'DRIVER_NOT_FOUND';
    case INVALID_REQUEST = 'INVALID_REQUEST';
    case UNSUPPORTED_CAPABILITY = 'UNSUPPORTED_CAPABILITY';
    case STORAGE_OPERATION_FAILED = 'STORAGE_OPERATION_FAILED';
}
