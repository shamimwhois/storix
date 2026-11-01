<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use ShamimStack\Storix\Enums\TransferDirection;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Exceptions\StorageOperationFailedException;
use ShamimStack\Storix\Facades\Storix;
use ShamimStack\Storix\Models\Transfer;
use ShamimStack\Storix\Tests\TestCase;

final class ProgressManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_tenant_and_owner_scoped_progress_snapshot(): void
    {
        $transfer = $this->createTransfer([
            'total_bytes' => 1000,
            'transferred_bytes' => 250,
            'total_chunks' => 4,
            'completed_chunks' => 1,
            'average_bytes_per_second' => 100,
        ]);

        $snapshot = Storix::progress()->forTransfer((string) $transfer->id, 'tenant-a', 'owner-a');

        self::assertSame((string) $transfer->id, $snapshot->transferId);
        self::assertSame(TransferDirection::UPLOAD, $snapshot->direction);
        self::assertSame(TransferState::TRANSFERRING, $snapshot->state);
        self::assertSame(1000, $snapshot->totalBytes);
        self::assertSame(250, $snapshot->transferredBytes);
        self::assertSame(750, $snapshot->remainingBytes);
        self::assertEquals(25.0, $snapshot->percent);
        self::assertEquals(100.0, $snapshot->averageBytesPerSecond);
        self::assertSame(8, $snapshot->etaSeconds);
        self::assertSame(4, $snapshot->totalChunks);
        self::assertSame(1, $snapshot->completedChunks);
        self::assertSame(0, $snapshot->revision);
    }

    public function test_unknown_or_zero_total_has_no_percentage_or_eta(): void
    {
        $transfer = $this->createTransfer([
            'total_bytes' => 0,
            'transferred_bytes' => 0,
            'average_bytes_per_second' => 500,
        ]);

        $snapshot = Storix::progress()->forTransfer((string) $transfer->id, 'tenant-a', 'owner-a');

        self::assertNull($snapshot->percent);
        self::assertNull($snapshot->etaSeconds);
        self::assertSame(0, $snapshot->remainingBytes);
    }

    public function test_completed_transfer_reports_one_hundred_percent_and_zero_eta(): void
    {
        $transfer = $this->createTransfer([
            'state' => TransferState::COMPLETED->value,
            'total_bytes' => 1000,
            'transferred_bytes' => 1000,
            'finished_at' => now(),
        ]);

        $snapshot = Storix::progress()->forTransfer((string) $transfer->id, 'tenant-a', 'owner-a');

        self::assertSame(100.0, $snapshot->percent);
        self::assertSame(0, $snapshot->etaSeconds);
    }

    public function test_completed_zero_byte_transfer_keeps_percentage_unknown(): void
    {
        $transfer = $this->createTransfer([
            'state' => TransferState::COMPLETED->value,
            'total_bytes' => 0,
            'transferred_bytes' => 0,
            'finished_at' => now(),
        ]);

        $snapshot = Storix::progress()->forTransfer((string) $transfer->id, 'tenant-a', 'owner-a');

        self::assertNull($snapshot->percent);
        self::assertSame(0, $snapshot->etaSeconds);
    }

    public function test_snapshot_updated_at_is_an_immutable_copy(): void
    {
        $transfer = $this->createTransfer();

        $snapshot = Storix::progress()->forTransfer((string) $transfer->id, 'tenant-a', 'owner-a');

        self::assertInstanceOf(\DateTimeImmutable::class, $snapshot->updatedAt);

        $before = $snapshot->updatedAt?->format('U.u');
        $modified = $snapshot->updatedAt?->modify('+1 second');

        self::assertNotSame($before, $modified?->format('U.u'));
        self::assertSame($before, $snapshot->updatedAt?->format('U.u'));
    }

    public function test_progress_lookup_does_not_disclose_missing_or_out_of_scope_transfers(): void
    {
        $transfer = $this->createTransfer();

        try {
            Storix::progress()->forTransfer((string) $transfer->id, 'tenant-b', 'owner-a');
            self::fail('An out-of-scope transfer must not be returned.');
        } catch (StorageOperationFailedException $exception) {
            self::assertSame('progress_lookup', $exception->operation);
        }
    }

    public function test_nonterminal_transfer_at_or_above_total_does_not_report_zero_eta_or_completion(): void
    {
        $transfer = $this->createTransfer([
            'total_bytes' => 1000,
            'transferred_bytes' => 1000,
            'average_bytes_per_second' => 100,
        ]);

        $snapshot = Storix::progress()->forTransfer((string) $transfer->id, 'tenant-a', 'owner-a');

        self::assertSame(99.99, $snapshot->percent);
        self::assertNull($snapshot->etaSeconds);
        self::assertSame(TransferState::TRANSFERRING, $snapshot->state);
    }

    /** @param array<string, mixed> $overrides */
    private function createTransfer(array $overrides = []): Transfer
    {
        return Transfer::query()->create(array_merge([
            'tenant_id' => 'tenant-a',
            'owner_id' => 'owner-a',
            'direction' => TransferDirection::UPLOAD->value,
            'driver' => 'local',
            'state' => TransferState::TRANSFERRING->value,
            'total_bytes' => 1000,
            'transferred_bytes' => 0,
            'total_chunks' => 0,
            'completed_chunks' => 0,
            'progress_revision' => 0,
        ], $overrides));
    }
}
