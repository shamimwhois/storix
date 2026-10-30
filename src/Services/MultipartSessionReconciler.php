<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Services;

use Illuminate\Support\Collection;
use ShamimStack\Storix\Contracts\MultipartStorageDriver;
use ShamimStack\Storix\DTOs\ChunkDescriptor;
use ShamimStack\Storix\DTOs\ChunkReconciliationResult;
use ShamimStack\Storix\Models\Chunk;

final class MultipartSessionReconciler
{
    public function __construct(private readonly ChunkReconciler $chunks) {}

    /**
     * Reconcile database chunk state with both the new source bytes and the provider's live session.
     *
     * Invalid or untracked provider parts are discarded so a later write can safely reuse the part
     * number. The caller remains responsible for persisting invalidated chunks as pending.
     *
     * @param  list<ChunkDescriptor>  $plan
     * @param  Collection<int, Chunk>  $persistedChunks
     * @param  callable(ChunkDescriptor): string  $hashStagedChunk
     */
    public function reconcile(
        MultipartStorageDriver $driver,
        string $uploadId,
        array $plan,
        Collection $persistedChunks,
        callable $hashStagedChunk,
        ?callable $renewLease = null,
    ): ChunkReconciliationResult {
        $metadata = $this->chunks->reconcile($plan, $persistedChunks, $hashStagedChunk);
        $reusableByIndex = [];

        foreach ($metadata->reusableChunks as $chunk) {
            $reusableByIndex[(int) $chunk->getRawOriginal('chunk_index')] = $chunk;
        }

        $persistedByIndex = [];

        foreach ($persistedChunks as $chunk) {
            $persistedByIndex[(int) $chunk->getRawOriginal('chunk_index')] = $chunk;
        }

        $reusable = [];
        $invalidated = [];
        $verifiedBytes = 0;
        $reusableParts = [];

        foreach ($plan as $descriptor) {
            if ($renewLease !== null) {
                $renewLease();
            }

            $partNumber = $descriptor->index + 1;
            $providerPart = $driver->inspectMultipartPart($uploadId, $partNumber);

            if ($renewLease !== null) {
                $renewLease();
            }

            $chunk = $persistedByIndex[$descriptor->index];

            if (isset($reusableByIndex[$descriptor->index]) === false || $providerPart === null) {
                if ($providerPart !== null) {
                    $driver->discardMultipartPart($uploadId, $partNumber);
                    if ($renewLease !== null) {
                        $renewLease();
                    }
                }

                $invalidated[] = $chunk;

                continue;
            }

            $storedReference = $chunk->getRawOriginal('driver_reference');
            $referenceMatches = $storedReference === null
                || $storedReference === $providerPart->driverReference;

            if (
                $providerPart->partNumber !== $partNumber
                || $providerPart->sizeBytes !== $descriptor->sizeBytes
                || hash_equals(
                    strtolower((string) $chunk->getRawOriginal('sha256')),
                    strtolower($providerPart->sha256),
                ) === false
                || $referenceMatches === false
            ) {
                $driver->discardMultipartPart($uploadId, $partNumber);
                if ($renewLease !== null) {
                    $renewLease();
                }
                $invalidated[] = $chunk;

                continue;
            }

            $reusable[] = $chunk;
            $reusableParts[] = $providerPart;
            $verifiedBytes += $descriptor->sizeBytes;
        }

        return new ChunkReconciliationResult($reusable, $invalidated, $verifiedBytes, $reusableParts);
    }
}
