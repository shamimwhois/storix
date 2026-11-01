<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Enums;

enum FailureCategory: string
{
    case TRANSIENT = 'transient';
    case RATE_LIMITED = 'rate_limited';
    case PERMANENT = 'permanent';
    case UNKNOWN = 'unknown';
}
