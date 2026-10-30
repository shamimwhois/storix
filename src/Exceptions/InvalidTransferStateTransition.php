<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Exceptions;

use DomainException;
use ShamimStack\Storix\Enums\TransferState;

final class InvalidTransferStateTransition extends DomainException
{
    public function __construct(TransferState $from, TransferState $to)
    {
        parent::__construct(sprintf('Transfer state cannot transition from [%s] to [%s].', $from->value, $to->value));
    }
}
