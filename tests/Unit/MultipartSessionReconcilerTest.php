<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Unit;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use ShamimStack\Storix\Drivers\Local\LocalStorageDriver;
use ShamimStack\Storix\DTOs\BeginMultipartWriteRequest;
use ShamimStack\Storix\DTOs\ChunkDescriptor;
use ShamimStack\Storix\DTOs\WriteMultipartPartRequest;
use ShamimStack\Storix\Enums\ChunkState;
use ShamimStack\Storix\Models\Chunk;
use ShamimStack\Storix\Services\ChunkReconciler;
use ShamimStack\Storix\Services\MultipartSessionReconciler;

final class MultipartSessionReconcilerTest extends TestCase
{
    public function test_it_reuses_a_part_only_when_source_metadata_and_provider_bytes_match(): void
    {
        $driver = new LocalStorageDriver(sys_get_temp_dir().'/storix-reconcile-'.bin2hex(random_bytes(8)));
        $uploadId = $driver->beginMultipartWrite(new BeginMultipartWriteRequest('resume/object.bin'));
        $this->writePart($driver, $uploadId, 'matching-content');

        try {
            $chunk = $this->verifiedChunk('matching-content');
            $result = (new MultipartSessionReconciler(new ChunkReconciler))->reconcile(
                $driver,
                $uploadId,
                [new ChunkDescriptor(0, 0, strlen('matching-content'))],
                new Collection([$chunk]),
                static fn (): string => hash('sha256', 'matching-content'),
            );

            self::assertSame([$chunk], $result->reusableChunks);
            self::assertSame([], $result->invalidatedChunks);
            self::assertSame(strlen('matching-content'), $result->verifiedBytes);
        } finally {
            $driver->abortMultipartWrite($uploadId);
        }
    }

    public function test_it_discards_a_provider_part_when_its_bytes_do_not_match_the_source(): void
    {
        $driver = new LocalStorageDriver(sys_get_temp_dir().'/storix-reconcile-'.bin2hex(random_bytes(8)));
        $uploadId = $driver->beginMultipartWrite(new BeginMultipartWriteRequest('resume/object.bin'));
        $this->writePart($driver, $uploadId, 'tampered-content');

        try {
            $chunk = $this->verifiedChunk('expected-content');
            $result = (new MultipartSessionReconciler(new ChunkReconciler))->reconcile(
                $driver,
                $uploadId,
                [new ChunkDescriptor(0, 0, strlen('expected-content'))],
                new Collection([$chunk]),
                static fn (): string => hash('sha256', 'expected-content'),
            );

            self::assertSame([], $result->reusableChunks);
            self::assertSame([$chunk], $result->invalidatedChunks);
            self::assertNull($driver->inspectMultipartPart($uploadId, 1));
        } finally {
            $driver->abortMultipartWrite($uploadId);
        }
    }

    private function writePart(LocalStorageDriver $driver, string $uploadId, string $contents): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        try {
            $driver->writeMultipartPart(new WriteMultipartPartRequest(
                uploadId: $uploadId,
                partNumber: 1,
                stream: $stream,
            ));
        } finally {
            fclose($stream);
        }
    }

    private function verifiedChunk(string $contents): Chunk
    {
        $chunk = new Chunk;
        $chunk->setRawAttributes([
            'chunk_index' => 0,
            'offset_bytes' => 0,
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'state' => ChunkState::VERIFIED->value,
            'driver_reference' => null,
            'verified_at' => '2026-10-31 00:00:00',
        ], true);

        return $chunk;
    }
}
