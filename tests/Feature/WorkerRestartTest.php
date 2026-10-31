<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ShamimStack\Storix\Contracts\MultipartStorageDriver;
use ShamimStack\Storix\Contracts\Repositories\ChunkRepository;
use ShamimStack\Storix\Contracts\Repositories\StoredFileRepository;
use ShamimStack\Storix\Contracts\Repositories\TransferRepository;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\DTOs\BeginMultipartWriteRequest;
use ShamimStack\Storix\DTOs\UploadRequest;
use ShamimStack\Storix\DTOs\WriteMultipartPartRequest;
use ShamimStack\Storix\Enums\ChunkState;
use ShamimStack\Storix\Enums\ObjectVisibility;
use ShamimStack\Storix\Enums\TransferDirection;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Models\Chunk;
use ShamimStack\Storix\Models\StoredFile;
use ShamimStack\Storix\Models\Transfer;
use ShamimStack\Storix\Tests\TestCase;
use ShamimStack\Storix\Uploads\UploadManager;

final class WorkerRestartTest extends TestCase
{
    private string $databasePath;

    private string $storageRoot;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir().'/storix-e07-'.Str::uuid().'.sqlite';
        touch($this->databasePath);
        $this->storageRoot = sys_get_temp_dir().'/storix-e07-storage-'.Str::uuid();

        parent::setUp();

        config([
            'storix.drivers.local.root' => $this->storageRoot,
            'storix.transfers.chunk_size' => 4,
        ]);

        $this->artisan('migrate:fresh')->assertExitCode(0);
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        File::deleteDirectory($this->storageRoot);

        if (is_file($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_upload_resumes_after_the_laravel_application_container_is_recreated(): void
    {
        $payload = 'abcdefghij';
        $originalName = 'restart.txt';
        $tenantId = 'tenant-restart';
        $ownerId = 'owner-restart';
        $idempotencyKey = 'restart-worker-upload';
        $storageKey = 'uploads/restart.txt';
        $metadata = [];
        $fingerprint = hash('sha256', json_encode([
            'sha256' => hash('sha256', $payload),
            'size_bytes' => strlen($payload),
            'original_name' => $originalName,
            'tenant_id' => $tenantId,
            'owner_id' => $ownerId,
            'driver' => 'local',
            'directory_id' => null,
            'mime_type' => null,
            'visibility' => ObjectVisibility::PRIVATE->value,
            'metadata' => $metadata,
        ], JSON_THROW_ON_ERROR));

        $driver = app(DriverManager::class)->driver('local');
        self::assertInstanceOf(MultipartStorageDriver::class, $driver);

        $uploadId = $driver->beginMultipartWrite(new BeginMultipartWriteRequest(key: $storageKey));
        $partStream = $this->stream(substr($payload, 0, 4));

        try {
            $part = $driver->writeMultipartPart(new WriteMultipartPartRequest(
                uploadId: $uploadId,
                partNumber: 1,
                stream: $partStream,
            ));
        } finally {
            fclose($partStream);
        }

        $file = app(StoredFileRepository::class)->create([
            'tenant_id' => $tenantId,
            'owner_id' => $ownerId,
            'directory_id' => null,
            'driver' => 'local',
            'name' => $originalName,
            'original_name' => $originalName,
            'extension' => 'txt',
            'mime_type' => null,
            'size_bytes' => strlen($payload),
            'sha256' => hash('sha256', $payload),
            'storage_key' => $storageKey,
            'status' => 'processing',
            'visibility' => ObjectVisibility::PRIVATE,
            'metadata' => $metadata,
        ]);

        $transfer = app(TransferRepository::class)->create([
            'file_id' => $file->id,
            'tenant_id' => $tenantId,
            'owner_id' => $ownerId,
            'direction' => TransferDirection::UPLOAD,
            'driver' => 'local',
            'state' => TransferState::TRANSFERRING,
            'source_name' => $originalName,
            'total_bytes' => strlen($payload),
            'transferred_bytes' => $part->sizeBytes,
            'total_chunks' => 3,
            'completed_chunks' => 1,
            'fingerprint' => $fingerprint,
            'idempotency_key' => $idempotencyKey,
            'attempts' => 1,
            'max_attempts' => 5,
            'multipart_upload_id' => $uploadId,
            'multipart_started_at' => now(),
        ]);

        $chunks = app(ChunkRepository::class);
        $chunks->create([
            'transfer_id' => $transfer->id,
            'chunk_index' => 0,
            'offset_bytes' => 0,
            'size_bytes' => 4,
            'sha256' => $part->sha256,
            'state' => ChunkState::VERIFIED,
            'driver_reference' => $part->driverReference,
            'attempts' => 1,
            'verified_at' => now(),
        ]);
        $chunks->create([
            'transfer_id' => $transfer->id,
            'chunk_index' => 1,
            'offset_bytes' => 4,
            'size_bytes' => 4,
            'sha256' => null,
            'state' => ChunkState::PENDING,
            'attempts' => 0,
        ]);
        $chunks->create([
            'transfer_id' => $transfer->id,
            'chunk_index' => 2,
            'offset_bytes' => 8,
            'size_bytes' => 2,
            'sha256' => null,
            'state' => ChunkState::PENDING,
            'attempts' => 0,
        ]);

        // Recreate the Laravel application while retaining the file-backed database
        // and provider multipart workspace, exactly as a restarted worker would.
        $this->refreshApplication();

        config([
            'storix.drivers.local.root' => $this->storageRoot,
            'storix.transfers.chunk_size' => 1,
        ]);

        $manager = app(UploadManager::class);
        $stream = $this->stream($payload);

        try {
            $result = $manager->upload(new UploadRequest(
                stream: $stream,
                originalName: $originalName,
                tenantId: $tenantId,
                ownerId: $ownerId,
                idempotencyKey: $idempotencyKey,
            ));
        } finally {
            fclose($stream);
        }

        self::assertSame($transfer->id, $result->transfer->id);
        self::assertSame($file->id, $result->file->id);
        self::assertSame(TransferState::COMPLETED, $result->transfer->state);
        self::assertSame($payload, file_get_contents($this->storageRoot.'/'.$storageKey));
        self::assertSame(1, Transfer::query()->count());
        self::assertSame(1, StoredFile::query()->count());
        self::assertSame(3, Chunk::query()->where('transfer_id', $transfer->id)->where('state', ChunkState::VERIFIED)->count());
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
