<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Enums;

enum ChunkState: string
{
    case PENDING = 'pending';
    case UPLOADING = 'uploading';
    case VERIFIED = 'verified';
    case FAILED = 'failed';
}
