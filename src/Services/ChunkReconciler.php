<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Services;

use Illuminate\Support\Collection;
use ShamimStack\Storix\DTOs\ChunkDescriptor;
use ShamimStack\Storix\DTOs\ChunkReconciliationResult;
use ShamimStack\Storix\Enums\ChunkState;
use ShamimStack\Storix\Exceptions\UploadIntegrityException;
use ShamimStack\Storix\Models\Chunk;

final class ChunkReconciler
{
    /**
     * Reconcile persisted chunk metadata against the deterministic plan and freshly staged bytes.
     *
     * The callback must return the SHA-256 digest of the exact source byte range described by
     * the supplied descriptor. Provider-side part inspection remains the driver's responsibility.
     *
     * @param  list<ChunkDescriptor>  $plan
     * @param  Collection<int, Chunk>  $chunks
     * @param  callable(ChunkDescriptor): string  $hashStagedChunk
     */
    public function reconcile(
        array $plan,
        Collection $chunks,
        callable $hashStagedChunk,
    ): ChunkReconciliationResult {
        $expectedOffset = 0;
        $expectedByIndex = [];

        foreach ($plan as $index => $descriptor) {
            if (
                $descriptor->index !== $index
                || $descriptor->offsetBytes !== $expectedOffset
                || isset($expectedByIndex[$descriptor->index])
            ) {
                throw new UploadIntegrityException;
            }

            $expectedByIndex[$descriptor->index] = $descriptor;
            $expectedOffset += $descriptor->sizeBytes;
        }

        if ($chunks->count() !== count($plan)) {
            throw new UploadIntegrityException;
        }

        $persistedByIndex = [];

        foreach ($chunks as $chunk) {
            $index = $chunk->chunk_index;

            if (
                isset($persistedByIndex[$index])
                || array_key_exists($index, $expectedByIndex) === false
            ) {
                throw new UploadIntegrityException;
            }

            $persistedByIndex[$index] = $chunk;
        }

        $reusable = [];
        $invalidated = [];
        $verifiedBytes = 0;

        foreach ($plan as $descriptor) {
            $chunk = $persistedByIndex[$descriptor->index];

            if (
                $chunk->offset_bytes !== $descriptor->offsetBytes
                || $chunk->size_bytes !== $descriptor->sizeBytes
            ) {
                throw new UploadIntegrityException;
            }

            if (
                $chunk->state !== ChunkState::VERIFIED
                || $chunk->verified_at === null
                || is_string($chunk->sha256) === false
                || preg_match('/^[a-f0-9]{64}$/iD', $chunk->sha256) !== 1
            ) {
                $invalidated[] = $chunk;

                continue;
            }

            $stagedDigest = strtolower($hashStagedChunk($descriptor));

            if (
                preg_match('/^[a-f0-9]{64}$/D', $stagedDigest) !== 1
                || hash_equals(strtolower($chunk->sha256), $stagedDigest) === false
            ) {
                $invalidated[] = $chunk;

                continue;
            }

            $reusable[] = $chunk;
            $verifiedBytes += $descriptor->sizeBytes;
        }

        return new ChunkReconciliationResult($reusable, $invalidated, $verifiedBytes);
    }
}
