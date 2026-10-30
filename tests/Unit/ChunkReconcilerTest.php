<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Unit;

use Illuminate\Support\Collection;
use ShamimStack\Storix\Tests\TestCase;
use ShamimStack\Storix\DTOs\ChunkDescriptor;
use ShamimStack\Storix\Enums\ChunkState;
use ShamimStack\Storix\Exceptions\UploadIntegrityException;
use ShamimStack\Storix\Models\Chunk;
use ShamimStack\Storix\Services\ChunkReconciler;

final class ChunkReconcilerTest extends TestCase
{
    public function test_it_reuses_only_verified_chunks_whose_staged_bytes_still_match(): void
    {
        $first = $this->chunk(0, 0, 4, hash('sha256', 'abcd'), ChunkState::VERIFIED);
        $second = $this->chunk(1, 4, 4, hash('sha256', 'efgh'), ChunkState::VERIFIED);
        $plan = [
            new ChunkDescriptor(0, 0, 4),
            new ChunkDescriptor(1, 4, 4),
        ];

        $result = (new ChunkReconciler)->reconcile(
            $plan,
            new Collection([$first, $second]),
            static fn (ChunkDescriptor $descriptor): string => hash(
                'sha256',
                $descriptor->index === 0 ? 'abcd' : 'changed',
            ),
        );

        self::assertSame([$first], $result->reusableChunks);
        self::assertSame([$second], $result->invalidatedChunks);
        self::assertSame(4, $result->verifiedBytes);
        self::assertSame(1, $result->completedChunks());
    }

    public function test_it_does_not_reuse_chunks_without_a_verified_timestamp(): void
    {
        $chunk = $this->chunk(0, 0, 4, hash('sha256', 'data'), ChunkState::VERIFIED);
        $chunk->verified_at = null;

        $result = (new ChunkReconciler)->reconcile(
            [new ChunkDescriptor(0, 0, 4)],
            new Collection([$chunk]),
            static fn (): string => hash('sha256', 'data'),
        );

        self::assertSame([], $result->reusableChunks);
        self::assertSame([$chunk], $result->invalidatedChunks);
        self::assertSame(0, $result->verifiedBytes);
    }

    public function test_it_rejects_missing_or_unexpected_persisted_chunks(): void
    {
        $this->expectException(UploadIntegrityException::class);

        (new ChunkReconciler)->reconcile(
            [new ChunkDescriptor(0, 0, 4)],
            new Collection,
            static fn (): string => hash('sha256', 'data'),
        );
    }

    public function test_it_rejects_a_persisted_chunk_with_a_changed_layout(): void
    {
        $chunk = $this->chunk(0, 1, 4, hash('sha256', 'data'), ChunkState::VERIFIED);

        $this->expectException(UploadIntegrityException::class);

        (new ChunkReconciler)->reconcile(
            [new ChunkDescriptor(0, 0, 4)],
            new Collection([$chunk]),
            static fn (): string => hash('sha256', 'data'),
        );
    }

    private function chunk(
        int $index,
        int $offset,
        int $size,
        string $sha256,
        ChunkState $state,
    ): Chunk {
        $chunk = new Chunk;
        $chunk->forceFill([
            'chunk_index' => $index,
            'offset_bytes' => $offset,
            'size_bytes' => $size,
            'sha256' => $sha256,
            'state' => $state,
            'verified_at' => now(),
        ]);

        return $chunk;
    }
}
