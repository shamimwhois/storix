<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ShamimStack\Storix\Contracts\MultipartStorageDriver;
use ShamimStack\Storix\Contracts\Repositories\ChunkRepository;
use ShamimStack\Storix\Contracts\Repositories\DirectoryRepository;
use ShamimStack\Storix\Contracts\StorageDriver;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StatObjectRequest;
use ShamimStack\Storix\DTOs\StorageObjectMetadata;
use ShamimStack\Storix\DTOs\UploadRequest;
use ShamimStack\Storix\DTOs\WriteResult;
use ShamimStack\Storix\DTOs\WriteStreamRequest;
use ShamimStack\Storix\Enums\ChunkState;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Exceptions\IdempotencyConflictException;
use ShamimStack\Storix\Exceptions\StorageOperationFailedException;
use ShamimStack\Storix\Exceptions\UploadIntegrityException;
use ShamimStack\Storix\Exceptions\UploadTooLargeException;
use ShamimStack\Storix\Exceptions\UploadValidationException;
use ShamimStack\Storix\Facades\Storix;
use ShamimStack\Storix\Models\Chunk;
use ShamimStack\Storix\Models\StoredFile;
use ShamimStack\Storix\Models\Transfer;
use ShamimStack\Storix\Tests\TestCase;

final class UploadManagerTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/storix-e05-'.Str::uuid();
        config(['storix.drivers.local.root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_uploads_a_binary_stream_and_verifies_the_persisted_object(): void
    {
        $contents = "\0Storix\x01\x02binary\ncontent";
        $stream = $this->stream($contents);

        try {
            $result = Storix::uploads()->upload(new UploadRequest(
                stream: $stream,
                originalName: 'archive.bin',
                tenantId: 'tenant-a',
                ownerId: 'owner-a',
                expectedSizeBytes: strlen($contents),
            ));

            self::assertTrue(is_resource($stream));
            self::assertSame('available', $result->file->status);
            self::assertSame(strlen($contents), $result->file->size_bytes);
            self::assertSame(hash('sha256', $contents), $result->file->sha256);
            self::assertSame(TransferState::COMPLETED, $result->transfer->state);
            self::assertSame(strlen($contents), $result->transfer->transferred_bytes);
            self::assertSame(1, $result->transfer->total_chunks);
            self::assertSame(1, $result->transfer->completed_chunks);
            self::assertFileExists($this->root.'/'.$result->file->storage_key);
            self::assertSame(1, StoredFile::query()->count());
            self::assertSame(1, Transfer::query()->count());
        } finally {
            fclose($stream);
        }
    }

    public function test_it_accepts_unusual_display_filename_suffixes_without_using_them_as_storage_suffixes(): void
    {
        foreach (['Meeting notes 2026.05 draft', '文件.中文'] as $originalName) {
            $stream = $this->stream('payload');

            try {
                $result = Storix::uploads()->upload(new UploadRequest(
                    stream: $stream,
                    originalName: $originalName,
                ));

                self::assertNull($result->file->extension);
                self::assertStringNotContainsString('.05 draft', $result->file->storage_key);
                self::assertStringNotContainsString('.中文', $result->file->storage_key);
            } finally {
                fclose($stream);
            }
        }

        self::assertSame(2, StoredFile::query()->count());
    }

    public function test_it_plans_persists_and_verifies_multiple_chunks(): void
    {
        config(['storix.transfers.chunk_size' => 4]);
        $contents = 'abcdefghij';
        $stream = $this->stream($contents);

        try {
            $result = Storix::uploads()->upload(new UploadRequest(
                stream: $stream,
                originalName: 'chunked.txt',
            ));

            self::assertSame(3, $result->transfer->total_chunks);
            self::assertSame(3, $result->transfer->completed_chunks);
            self::assertSame(strlen($contents), $result->transfer->transferred_bytes);

            $chunks = Chunk::query()
                ->where('transfer_id', $result->transfer->id)
                ->orderBy('chunk_index')
                ->get();

            self::assertCount(3, $chunks);
            self::assertSame([0, 4, 8], $chunks->pluck('offset_bytes')->all());
            self::assertSame([4, 4, 2], $chunks->pluck('size_bytes')->all());
            self::assertSame(
                [
                    hash('sha256', 'abcd'),
                    hash('sha256', 'efgh'),
                    hash('sha256', 'ij'),
                ],
                $chunks->pluck('sha256')->all(),
            );

            foreach ($chunks as $chunk) {
                self::assertSame(ChunkState::VERIFIED, $chunk->state);
            }
        } finally {
            fclose($stream);
        }
    }

    public function test_it_persists_failed_upload_state_when_a_multipart_part_write_fails(): void
    {
        $driver = $this->createMock(MultipartStorageDriver::class);
        $driver->method('name')->willReturn('mp');
        $driver->method('capabilities')->willReturn(new DriverCapabilities(
            supportsMultipartWrites: true,
        ));
        $driver->method('beginMultipartWrite')->willReturn('upload-1');
        $driver->method('writeMultipartPart')->willThrowException(
            new StorageOperationFailedException('multipart_write'),
        );
        $driver->expects(self::once())->method('abortMultipartWrite')->with('upload-1');
        $driver->expects(self::once())->method('delete');

        $this->app->make(DriverManager::class)->register('mp', $driver);
        $stream = $this->stream('multipart-payload');

        try {
            $this->assertUploadException(
                StorageOperationFailedException::class,
                fn () => Storix::uploads()->upload(new UploadRequest(
                    stream: $stream,
                    originalName: 'multipart.txt',
                    driver: 'mp',
                )),
            );
        } finally {
            fclose($stream);
        }

        $file = StoredFile::query()->firstOrFail();
        $transfer = Transfer::query()->firstOrFail();
        $chunk = Chunk::query()->firstOrFail();

        self::assertSame('failed', $file->status);
        self::assertSame(TransferState::FAILED, $transfer->state);
        self::assertSame(ChunkState::FAILED, $chunk->state);
        self::assertNotNull($chunk->last_error_code);
        self::assertSame(1, StoredFile::query()->count());
        self::assertSame(1, Transfer::query()->count());
        self::assertSame(1, Chunk::query()->count());
    }

    public function test_it_aborts_multipart_write_and_preserves_upload_error_when_chunk_failure_persistence_fails(): void
    {
        $chunkRepository = $this->app->make(ChunkRepository::class);
        $failingRepository = $this->createMock(ChunkRepository::class);
        $failingRepository->method('create')->willReturnCallback(
            fn (array $attributes) => $chunkRepository->create($attributes),
        );
        $failingRepository->method('findByIndex')->willReturnCallback(
            fn (string $transferId, int $chunkIndex) => $chunkRepository->findByIndex($transferId, $chunkIndex),
        );
        $failingRepository->method('save')->willReturnCallback(
            fn (Chunk $chunk) => $chunkRepository->save($chunk),
        );
        $failingRepository->method('deleteForTransfer')->willReturnCallback(
            fn (string $transferId) => $chunkRepository->deleteForTransfer($transferId),
        );
        $failingRepository->method('forTransfer')->willThrowException(
            new \RuntimeException('Simulated chunk persistence failure.'),
        );
        $this->app->instance(
            ChunkRepository::class,
            $failingRepository,
        );

        $driver = $this->createMock(MultipartStorageDriver::class);
        $driver->method('name')->willReturn('mp');
        $driver->method('capabilities')->willReturn(new DriverCapabilities(
            supportsMultipartWrites: true,
        ));
        $driver->method('beginMultipartWrite')->willReturn('upload-2');
        $driver->method('writeMultipartPart')->willThrowException(
            new StorageOperationFailedException('multipart_write'),
        );
        $driver->expects(self::once())->method('abortMultipartWrite')->with('upload-2');
        $driver->expects(self::once())->method('delete');

        $this->app->make(DriverManager::class)->register('mp', $driver);
        $stream = $this->stream('multipart-payload');

        try {
            $exception = $this->assertUploadException(
                StorageOperationFailedException::class,
                fn () => Storix::uploads()->upload(new UploadRequest(
                    stream: $stream,
                    originalName: 'multipart-persistence-failure.txt',
                    driver: 'mp',
                )),
            );

            self::assertSame('STORAGE_OPERATION_FAILED', $exception->errorCode->value);
        } finally {
            fclose($stream);
        }

        self::assertSame(TransferState::FAILED, Transfer::query()->firstOrFail()->state);
    }

    public function test_it_accepts_an_empty_file(): void
    {
        $stream = $this->stream('');

        try {
            $result = Storix::uploads()->upload(new UploadRequest(
                stream: $stream,
                originalName: 'empty.txt',
            ));

            self::assertSame(0, $result->file->size_bytes);
            self::assertSame(hash('sha256', ''), $result->file->sha256);
            self::assertSame(TransferState::COMPLETED, $result->transfer->state);
        } finally {
            fclose($stream);
        }
    }

    public function test_it_replays_a_completed_idempotent_upload_without_writing_again(): void
    {
        $firstStream = $this->stream('same-payload');
        $secondStream = $this->stream('same-payload');

        try {
            $first = Storix::uploads()->upload(new UploadRequest(
                stream: $firstStream,
                originalName: 'same.txt',
                ownerId: 'owner-a',
                idempotencyKey: 'same-request',
            ));
            $second = Storix::uploads()->upload(new UploadRequest(
                stream: $secondStream,
                originalName: 'same.txt',
                ownerId: 'owner-a',
                idempotencyKey: 'same-request',
            ));

            self::assertFalse($first->replayed);
            self::assertTrue($second->replayed);
            self::assertSame($first->file->id, $second->file->id);
            self::assertSame($first->transfer->id, $second->transfer->id);
            self::assertSame(1, StoredFile::query()->count());
            self::assertSame(1, Transfer::query()->count());
        } finally {
            fclose($firstStream);
            fclose($secondStream);
        }
    }

    public function test_it_replays_a_completed_upload_before_applying_a_changed_chunk_plan(): void
    {
        $payload = str_repeat('x', 10_001);
        config(['storix.transfers.chunk_size' => 10_001]);
        $firstStream = $this->stream($payload);
        $retryStream = $this->stream($payload);

        try {
            $first = Storix::uploads()->upload(new UploadRequest(
                stream: $firstStream,
                originalName: 'same.txt',
                ownerId: 'owner-a',
                idempotencyKey: 'chunk-plan-replay',
            ));

            self::assertSame(1, $first->transfer->total_chunks);

            config(['storix.transfers.chunk_size' => 1]);

            $retry = Storix::uploads()->upload(new UploadRequest(
                stream: $retryStream,
                originalName: 'same.txt',
                ownerId: 'owner-a',
                idempotencyKey: 'chunk-plan-replay',
            ));

            self::assertTrue($retry->replayed);
            self::assertSame($first->file->id, $retry->file->id);
            self::assertSame($first->transfer->id, $retry->transfer->id);
            self::assertSame(1, $retry->transfer->total_chunks);
            self::assertSame(1, Transfer::query()->count());
            self::assertSame(1, StoredFile::query()->count());
        } finally {
            fclose($firstStream);
            fclose($retryStream);
        }
    }

    public function test_it_rejects_reusing_an_idempotency_key_for_different_content(): void
    {
        $firstStream = $this->stream('first');
        $secondStream = $this->stream('second');

        try {
            Storix::uploads()->upload(new UploadRequest(
                stream: $firstStream,
                originalName: 'same.txt',
                ownerId: 'owner-a',
                idempotencyKey: 'same-request',
            ));

            $this->assertUploadException(IdempotencyConflictException::class, fn () => Storix::uploads()->upload(new UploadRequest(
                stream: $secondStream,
                originalName: 'same.txt',
                ownerId: 'owner-a',
                idempotencyKey: 'same-request',
            )));
        } finally {
            fclose($firstStream);
            fclose($secondStream);
        }

        self::assertSame(1, StoredFile::query()->count());
        self::assertSame(1, Transfer::query()->count());
    }

    public function test_it_rejects_idempotent_reuse_when_only_metadata_changes(): void
    {
        $firstStream = $this->stream('same-payload');
        $secondStream = $this->stream('same-payload');

        try {
            Storix::uploads()->upload(new UploadRequest(
                stream: $firstStream,
                originalName: 'same.txt',
                ownerId: 'owner-a',
                idempotencyKey: 'metadata-request',
                metadata: ['source' => 'first'],
            ));

            $this->assertUploadException(IdempotencyConflictException::class, fn () => Storix::uploads()->upload(new UploadRequest(
                stream: $secondStream,
                originalName: 'same.txt',
                ownerId: 'owner-a',
                idempotencyKey: 'metadata-request',
                metadata: ['source' => 'second'],
            )));
        } finally {
            fclose($firstStream);
            fclose($secondStream);
        }

        self::assertSame(1, StoredFile::query()->count());
        self::assertSame(1, Transfer::query()->count());
    }

    public function test_it_enforces_the_configured_size_limit_while_staging(): void
    {
        config(['storix.uploads.max_file_size_bytes' => 4]);
        $stream = $this->stream('12345');

        try {
            $this->assertUploadException(UploadTooLargeException::class, fn () => Storix::uploads()->upload(new UploadRequest(
                stream: $stream,
                originalName: 'large.txt',
            )));
        } finally {
            self::assertTrue(is_resource($stream));
            fclose($stream);
        }

        self::assertSame(0, StoredFile::query()->count());
        self::assertSame(0, Transfer::query()->count());
    }

    public function test_it_rejects_a_mismatched_expected_size_before_creating_records(): void
    {
        $stream = $this->stream('payload');

        try {
            $this->assertUploadException(UploadValidationException::class, fn () => Storix::uploads()->upload(new UploadRequest(
                stream: $stream,
                originalName: 'payload.txt',
                expectedSizeBytes: 8,
            )));
        } finally {
            fclose($stream);
        }

        self::assertSame(0, StoredFile::query()->count());
        self::assertSame(0, Transfer::query()->count());
    }

    public function test_it_rejects_directories_outside_the_upload_scope(): void
    {
        $directory = $this->app->make(DirectoryRepository::class)->create([
            'tenant_id' => 'tenant-a',
            'owner_id' => 'owner-a',
            'name' => 'Private',
            'path_key' => 'private',
            'driver' => 'local',
        ]);
        $stream = $this->stream('payload');

        try {
            $this->assertUploadException(UploadValidationException::class, fn () => Storix::uploads()->upload(new UploadRequest(
                stream: $stream,
                originalName: 'payload.txt',
                tenantId: 'tenant-a',
                ownerId: 'owner-b',
                directoryId: $directory->id,
            )));
        } finally {
            fclose($stream);
        }

        self::assertSame(0, StoredFile::query()->count());
        self::assertSame(0, Transfer::query()->count());
    }

    public function test_it_deletes_the_object_and_marks_metadata_failed_when_read_back_integrity_fails(): void
    {
        $driver = $this->createMock(StorageDriver::class);
        $driver->method('name')->willReturn('test');
        $driver->method('capabilities')->willReturn(new DriverCapabilities);
        $driver->method('writeStream')->willReturnCallback(function (WriteStreamRequest $request): WriteResult {
            $contents = stream_get_contents($request->stream);

            return new WriteResult(new StorageObjectMetadata(
                key: $request->key,
                sizeBytes: strlen($contents),
                checksumSha256: hash('sha256', $contents),
            ));
        });
        $driver->method('stat')->willReturnCallback(
            fn (StatObjectRequest $request): StorageObjectMetadata => new StorageObjectMetadata(
                key: $request->key,
                sizeBytes: strlen('original'),
            ),
        );
        $driver->method('readStream')->willReturnCallback(function (ReadStreamRequest $request): mixed {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, 'tampered');
            rewind($stream);

            return $stream;
        });
        $driver->expects(self::once())->method('delete');

        $this->app->make(DriverManager::class)->register('test', $driver);
        $source = $this->stream('original');

        try {
            $this->assertUploadException(UploadIntegrityException::class, fn () => Storix::uploads()->upload(new UploadRequest(
                stream: $source,
                originalName: 'integrity.txt',
                driver: 'test',
            )));
        } finally {
            fclose($source);
        }

        self::assertSame(1, StoredFile::query()->count());
        self::assertSame('failed', StoredFile::query()->firstOrFail()->status);
        self::assertSame(1, Transfer::query()->count());
        self::assertSame(TransferState::FAILED, Transfer::query()->firstOrFail()->state);
    }

    /**
     * @param  class-string<\Throwable>  $exceptionClass
     */
    private function assertUploadException(string $exceptionClass, callable $callback): \Throwable
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            self::assertInstanceOf($exceptionClass, $exception);

            return $exception;
        }

        self::fail("Expected exception {$exceptionClass} was not thrown.");
    }

    /** @return resource */
    private function stream(string $contents): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
