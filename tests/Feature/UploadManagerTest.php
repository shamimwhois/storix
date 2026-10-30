<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\MockObject\MockObject;
use ShamimStack\Storix\Contracts\Repositories\DirectoryRepository;
use ShamimStack\Storix\Contracts\Repositories\StoredFileRepository;
use ShamimStack\Storix\Contracts\Repositories\TransferRepository;
use ShamimStack\Storix\Contracts\StorageDriver;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StatObjectRequest;
use ShamimStack\Storix\DTOs\StorageObjectMetadata;
use ShamimStack\Storix\DTOs\UploadRequest;
use ShamimStack\Storix\DTOs\WriteResult;
use ShamimStack\Storix\DTOs\WriteStreamRequest;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Exceptions\IdempotencyConflictException;
use ShamimStack\Storix\Exceptions\UploadIntegrityException;
use ShamimStack\Storix\Exceptions\UploadTooLargeException;
use ShamimStack\Storix\Exceptions\UploadValidationException;
use ShamimStack\Storix\Facades\Storix;
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
            self::assertSame(0, $result->transfer->total_chunks);
            self::assertFileExists($this->root.'/'.$result->file->storage_key);
            self::assertSame(1, StoredFile::query()->count());
            self::assertSame(1, Transfer::query()->count());
        } finally {
            fclose($stream);
        }
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

            $this->expectException(IdempotencyConflictException::class);
            Storix::uploads()->upload(new UploadRequest(
                stream: $secondStream,
                originalName: 'same.txt',
                ownerId: 'owner-a',
                idempotencyKey: 'same-request',
            ));
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
            $this->expectException(UploadTooLargeException::class);
            Storix::uploads()->upload(new UploadRequest(
                stream: $stream,
                originalName: 'large.txt',
            ));
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
            $this->expectException(UploadValidationException::class);
            Storix::uploads()->upload(new UploadRequest(
                stream: $stream,
                originalName: 'payload.txt',
                expectedSizeBytes: 8,
            ));
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
            $this->expectException(UploadValidationException::class);
            Storix::uploads()->upload(new UploadRequest(
                stream: $stream,
                originalName: 'payload.txt',
                tenantId: 'tenant-a',
                ownerId: 'owner-b',
                directoryId: $directory->id,
            ));
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
            $this->expectException(UploadIntegrityException::class);
            Storix::uploads()->upload(new UploadRequest(
                stream: $source,
                originalName: 'integrity.txt',
                driver: 'test',
            ));
        } finally {
            fclose($source);
        }

        self::assertSame(1, StoredFile::query()->count());
        self::assertSame('failed', StoredFile::query()->firstOrFail()->status);
        self::assertSame(1, Transfer::query()->count());
        self::assertSame(TransferState::FAILED, Transfer::query()->firstOrFail()->state);
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
