<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Services;

use Illuminate\Support\Facades\DB;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Exceptions\InvalidTransferStateTransition;
use ShamimStack\Storix\Models\Transfer;

final class TransferStateMachine
{
    /** @var array<string, list<TransferState>> */
    private const TRANSITIONS = [
        'pending' => [TransferState::PREPARING, TransferState::CANCELLED, TransferState::EXPIRED, TransferState::FAILED],
        'preparing' => [TransferState::TRANSFERRING, TransferState::RETRY_WAIT, TransferState::PAUSED, TransferState::FAILED, TransferState::CANCELLED, TransferState::EXPIRED],
        'transferring' => [TransferState::VERIFYING, TransferState::RETRY_WAIT, TransferState::PAUSED, TransferState::FAILED, TransferState::CANCELLED],
        'retry_wait' => [TransferState::PREPARING, TransferState::TRANSFERRING, TransferState::FAILED, TransferState::CANCELLED, TransferState::EXPIRED],
        'paused' => [TransferState::PREPARING, TransferState::TRANSFERRING, TransferState::CANCELLED, TransferState::EXPIRED],
        'verifying' => [TransferState::COMPLETED, TransferState::RETRY_WAIT, TransferState::FAILED],
        'completed' => [],
        'failed' => [],
        'cancelled' => [],
        'expired' => [],
    ];

    public function transition(Transfer|string $transfer, TransferState $to): Transfer
    {
        $id = $transfer instanceof Transfer ? (string) $transfer->getKey() : $transfer;

        return DB::transaction(function () use ($id, $to): Transfer {
            $locked = Transfer::query()->lockForUpdate()->findOrFail($id);
            $from = $locked->state;

            if (! in_array($to, self::TRANSITIONS[$from->value], true)) {
                throw new InvalidTransferStateTransition($from, $to);
            }

            $locked->state = $to;

            if ($to === TransferState::PREPARING && $locked->started_at === null) {
                $locked->started_at = now();
            }

            if ($to->isTerminal()) {
                $locked->finished_at = now();
            }

            $locked->save();

            return $locked->refresh();
        });
    }

    /** @return array<string, list<TransferState>> */
    public function transitions(): array
    {
        return self::TRANSITIONS;
    }
}
