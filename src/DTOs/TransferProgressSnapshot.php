<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use DateTimeInterface;
use ShamimStack\Storix\Enums\TransferDirection;
use ShamimStack\Storix\Enums\TransferState;

final readonly class TransferProgressSnapshot
{
    public function __construct(
        public string $transferId,
        public TransferDirection $direction,
        public TransferState $state,
        public int $totalBytes,
        public int $transferredBytes,
        public int $remainingBytes,
        public ?float $percent,
        public ?float $averageBytesPerSecond,
        public ?int $etaSeconds,
        public int $totalChunks,
        public int $completedChunks,
        public int $revision,
        public ?DateTimeInterface $startedAt,
        public ?DateTimeInterface $updatedAt,
        public ?DateTimeInterface $finishedAt,
    ) {}
}
