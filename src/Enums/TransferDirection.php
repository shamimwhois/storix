<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Enums;

enum TransferDirection: string
{
    case UPLOAD = 'upload';
    case DOWNLOAD = 'download';
}
