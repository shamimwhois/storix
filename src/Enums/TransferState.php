<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Enums;

enum TransferState: string
{
    case PENDING = 'pending';
    case PREPARING = 'preparing';
    case TRANSFERRING = 'transferring';
    case PAUSED = 'paused';
    case RETRY_WAIT = 'retry_wait';
    case VERIFYING = 'verifying';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED, self::EXPIRED], true);
    }
}
