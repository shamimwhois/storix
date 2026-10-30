<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use ShamimStack\Storix\Enums\TransferDirection;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Exceptions\InvalidTransferStateTransition;
use ShamimStack\Storix\Models\Transfer;
use ShamimStack\Storix\Services\TransferStateMachine;
use ShamimStack\Storix\Tests\TestCase;

final class TransferStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_can_follow_the_successful_lifecycle(): void
    {
        $transfer = $this->makeTransfer();
        $machine = $this->app->make(TransferStateMachine::class);

        $machine->transition($transfer, TransferState::PREPARING);
        $machine->transition($transfer, TransferState::TRANSFERRING);
        $machine->transition($transfer, TransferState::VERIFYING);
        $completed = $machine->transition($transfer, TransferState::COMPLETED);

        self::assertSame(TransferState::COMPLETED, $completed->state);
        self::assertNotNull($completed->started_at);
        self::assertNotNull($completed->finished_at);
    }

    public function test_terminal_transfer_cannot_be_reopened(): void
    {
        $transfer = $this->makeTransfer();
        $machine = $this->app->make(TransferStateMachine::class);

        $machine->transition($transfer, TransferState::PREPARING);
        $machine->transition($transfer, TransferState::CANCELLED);

        $this->expectException(InvalidTransferStateTransition::class);

        $machine->transition($transfer, TransferState::TRANSFERRING);
    }

    public function test_transfer_cannot_complete_without_verification(): void
    {
        $transfer = $this->makeTransfer();

        $this->expectException(InvalidTransferStateTransition::class);

        $this->app->make(TransferStateMachine::class)->transition($transfer, TransferState::COMPLETED);
    }

    public function test_every_documented_transition_is_allowed(): void
    {
        $transitions = [
            [TransferState::PENDING, TransferState::PREPARING],
            [TransferState::PENDING, TransferState::CANCELLED],
            [TransferState::PENDING, TransferState::EXPIRED],
            [TransferState::PENDING, TransferState::FAILED],
            [TransferState::PREPARING, TransferState::TRANSFERRING],
            [TransferState::PREPARING, TransferState::RETRY_WAIT],
            [TransferState::PREPARING, TransferState::PAUSED],
            [TransferState::PREPARING, TransferState::FAILED],
            [TransferState::PREPARING, TransferState::CANCELLED],
            [TransferState::PREPARING, TransferState::EXPIRED],
            [TransferState::TRANSFERRING, TransferState::VERIFYING],
            [TransferState::TRANSFERRING, TransferState::RETRY_WAIT],
            [TransferState::TRANSFERRING, TransferState::PAUSED],
            [TransferState::TRANSFERRING, TransferState::FAILED],
            [TransferState::TRANSFERRING, TransferState::CANCELLED],
            [TransferState::RETRY_WAIT, TransferState::PREPARING],
            [TransferState::RETRY_WAIT, TransferState::TRANSFERRING],
            [TransferState::RETRY_WAIT, TransferState::FAILED],
            [TransferState::RETRY_WAIT, TransferState::CANCELLED],
            [TransferState::RETRY_WAIT, TransferState::EXPIRED],
            [TransferState::PAUSED, TransferState::PREPARING],
            [TransferState::PAUSED, TransferState::TRANSFERRING],
            [TransferState::PAUSED, TransferState::CANCELLED],
            [TransferState::PAUSED, TransferState::EXPIRED],
            [TransferState::VERIFYING, TransferState::COMPLETED],
            [TransferState::VERIFYING, TransferState::RETRY_WAIT],
            [TransferState::VERIFYING, TransferState::FAILED],
        ];

        $machine = $this->app->make(TransferStateMachine::class);

        foreach ($transitions as [$from, $to]) {
            $transfer = $this->makeTransfer($from);
            $result = $machine->transition($transfer, $to);

            self::assertSame($to, $result->state, sprintf(
                'Expected transition [%s] -> [%s] to be allowed.',
                $from->value,
                $to->value,
            ));
        }
    }

    private function makeTransfer(TransferState $state = TransferState::PENDING): Transfer
    {
        return Transfer::query()->create([
            'direction' => TransferDirection::UPLOAD,
            'driver' => 'local',
            'state' => $state,
            'total_bytes' => 100,
        ]);
    }
}
