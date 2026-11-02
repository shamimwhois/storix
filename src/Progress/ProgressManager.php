<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Progress;

use ShamimStack\Storix\Contracts\Repositories\TransferRepository;
use ShamimStack\Storix\DTOs\TransferProgressSnapshot;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Exceptions\StorageOperationFailedException;

final class ProgressManager
{
    public function __construct(
        private readonly TransferRepository $transfers,
    ) {}

    public function forTransfer(string $transferId, ?string $tenantId, ?string $ownerId): TransferProgressSnapshot
    {
        $transfer = $this->transfers->findScoped($transferId, $tenantId, $ownerId);

        if ($transfer === null) {
            // Keep missing and out-of-scope transfers indistinguishable.
            throw new StorageOperationFailedException('progress_lookup');
        }

        $totalBytes = max(0, (int) $transfer->total_bytes);
        $transferredBytes = max(0, (int) $transfer->transferred_bytes);
        $totalChunks = max(0, (int) $transfer->total_chunks);
        $completedChunks = max(0, (int) $transfer->completed_chunks);
        $state = $transfer->state;
        $completed = $state === TransferState::COMPLETED;
        $percent = null;

        if ($totalBytes > 0) {
            $percent = $completed
                ? 100.0
                : min(99.99, max(0.0, ($transferredBytes / $totalBytes) * 100));
        }

        $averageBytesPerSecond = $transfer->average_bytes_per_second === null
            ? null
            : max(0.0, (float) $transfer->average_bytes_per_second);

        $etaSeconds = $completed
            ? 0
            : ($averageBytesPerSecond !== null
                && $averageBytesPerSecond > 0
                && $totalBytes > 0
                && $transferredBytes < $totalBytes
                    ? (int) min(PHP_INT_MAX, ceil(($totalBytes - $transferredBytes) / $averageBytesPerSecond))
                    : null);

        return new TransferProgressSnapshot(
            transferId: (string) $transfer->getKey(),
            direction: $transfer->direction,
            state: $state,
            totalBytes: $totalBytes,
            transferredBytes: $transferredBytes,
            remainingBytes: max(0, $totalBytes - $transferredBytes),
            percent: $percent,
            averageBytesPerSecond: $averageBytesPerSecond,
            etaSeconds: $etaSeconds,
            totalChunks: $totalChunks,
            completedChunks: min($completedChunks, $totalChunks > 0 ? $totalChunks : $completedChunks),
            revision: max(0, (int) $transfer->progress_revision),
            startedAt: $transfer->started_at,
            updatedAt: $transfer->updated_at?->toImmutable(),
            finishedAt: $transfer->finished_at,
        );
    }
}
