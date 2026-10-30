<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use ShamimStack\Storix\Contracts\Repositories\ChunkRepository;
use ShamimStack\Storix\Contracts\Repositories\DirectoryRepository;
use ShamimStack\Storix\Contracts\Repositories\StoredFileRepository;
use ShamimStack\Storix\Contracts\Repositories\TransferRepository;
use ShamimStack\Storix\Enums\ChunkState;
use ShamimStack\Storix\Enums\ObjectVisibility;
use ShamimStack\Storix\Enums\TransferDirection;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Models\Directory;
use ShamimStack\Storix\Models\StoredFile;
use ShamimStack\Storix\Models\Transfer;
use ShamimStack\Storix\Tests\TestCase;

final class PersistenceRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_and_file_queries_are_scoped_to_owner_and_tenant(): void
    {
        $directories = $this->app->make(DirectoryRepository::class);
        $files = $this->app->make(StoredFileRepository::class);

        $directory = $directories->create([
            'tenant_id' => 'tenant-a',
            'owner_id' => 'owner-a',
            'name' => 'Invoices',
            'path_key' => 'invoices',
            'driver' => 'local',
        ]);

        $file = $files->create([
            'tenant_id' => 'tenant-a',
            'owner_id' => 'owner-a',
            'directory_id' => $directory->id,
            'driver' => 'local',
            'name' => 'invoice.pdf',
            'size_bytes' => 100,
            'sha256' => str_repeat('a', 64),
            'status' => 'available',
            'visibility' => 'private',
        ]);

        self::assertInstanceOf(Directory::class, $directories->findScoped($directory->id, 'tenant-a', 'owner-a'));
        self::assertNull($directories->findScoped($directory->id, 'tenant-b', 'owner-a'));
        self::assertSame($directory->id, $directories->findChild(null, 'Invoices', 'local', 'tenant-a', 'owner-a')?->id);
        self::assertNull($directories->findChild(null, 'Invoices', 'local', 'tenant-b', 'owner-a'));
        self::assertInstanceOf(StoredFile::class, $files->findScoped($file->id, 'tenant-a', 'owner-a'));
        self::assertNull($files->findScoped($file->id, 'tenant-a', 'owner-b'));
        self::assertSame(ObjectVisibility::PRIVATE, $file->visibility);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $file->id);
        self::assertSame(
            $file->id,
            $files->findByFingerprint(str_repeat('a', 64), 100, 'local', $directory->id, 'tenant-a', 'owner-a')?->id,
        );
        self::assertNull($files->findByFingerprint(str_repeat('a', 64), 100, 'remote', $directory->id, 'tenant-a', 'owner-a'));
        self::assertNull($files->findByFingerprint(str_repeat('a', 64), 100, 'local', null, 'tenant-a', 'owner-a'));
    }

    public function test_transfer_idempotency_is_scoped_and_reuses_existing_record(): void
    {
        $repository = $this->app->make(TransferRepository::class);
        $attributes = [
            'tenant_id' => 'tenant-a',
            'owner_id' => 'owner-a',
            'direction' => TransferDirection::UPLOAD,
            'driver' => 'local',
            'state' => TransferState::PENDING,
            'total_bytes' => 512,
            'idempotency_key' => 'request-123',
        ];

        $first = $repository->firstOrCreateIdempotent($attributes);
        $second = $repository->firstOrCreateIdempotent($attributes);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, Transfer::query()->count());

        $otherOwner = $repository->firstOrCreateIdempotent([...$attributes, 'owner_id' => 'owner-b']);
        $otherTenant = $repository->firstOrCreateIdempotent([...$attributes, 'tenant_id' => 'tenant-b']);

        self::assertNotSame($first->id, $otherOwner->id);
        self::assertNotSame($first->id, $otherTenant->id);

        $unscoped = [...$attributes, 'tenant_id' => null, 'owner_id' => null, 'idempotency_key' => 'unscoped-request'];
        $unscopedFirst = $repository->firstOrCreateIdempotent($unscoped);
        $unscopedSecond = $repository->firstOrCreateIdempotent($unscoped);

        self::assertSame($unscopedFirst->id, $unscopedSecond->id);
        self::assertSame(4, Transfer::query()->count());
    }

    public function test_blank_idempotency_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(TransferRepository::class)->firstOrCreateIdempotent([
            'direction' => TransferDirection::UPLOAD,
            'driver' => 'local',
            'state' => TransferState::PENDING,
            'idempotency_key' => '   ',
        ]);
    }

    public function test_database_constraint_rejects_duplicate_idempotency_scope_and_key(): void
    {
        $repository = $this->app->make(TransferRepository::class);
        $first = $repository->firstOrCreateIdempotent([
            'tenant_id' => 'tenant-a',
            'owner_id' => 'owner-a',
            'direction' => TransferDirection::UPLOAD,
            'driver' => 'local',
            'state' => TransferState::PENDING,
            'idempotency_key' => 'unique-request',
        ]);

        $this->expectException(QueryException::class);

        Transfer::query()->create([
            'tenant_id' => 'tenant-a',
            'owner_id' => 'owner-a',
            'direction' => TransferDirection::UPLOAD,
            'driver' => 'local',
            'state' => TransferState::PENDING,
            'idempotency_key' => 'unique-request',
            'idempotency_scope' => $first->idempotency_scope,
        ]);
    }

    public function test_chunks_are_ordered_and_unique_per_transfer(): void
    {
        $transfer = Transfer::query()->create([
            'direction' => TransferDirection::UPLOAD,
            'driver' => 'local',
            'state' => TransferState::PENDING,
            'total_bytes' => 20,
        ]);
        $chunks = $this->app->make(ChunkRepository::class);

        $chunks->create([
            'transfer_id' => $transfer->id,
            'chunk_index' => 1,
            'offset_bytes' => 10,
            'size_bytes' => 10,
            'state' => ChunkState::PENDING,
        ]);
        $chunks->create([
            'transfer_id' => $transfer->id,
            'chunk_index' => 0,
            'offset_bytes' => 0,
            'size_bytes' => 10,
            'state' => ChunkState::PENDING,
        ]);

        self::assertSame([0, 1], $chunks->forTransfer($transfer->id)->pluck('chunk_index')->all());
        self::assertSame(2, $transfer->chunks()->count());
    }

    public function test_database_constraint_rejects_duplicate_chunk_indexes(): void
    {
        $transfer = Transfer::query()->create([
            'direction' => TransferDirection::UPLOAD,
            'driver' => 'local',
            'state' => TransferState::PENDING,
            'total_bytes' => 10,
        ]);
        $chunks = $this->app->make(ChunkRepository::class);
        $attributes = [
            'transfer_id' => $transfer->id,
            'chunk_index' => 0,
            'offset_bytes' => 0,
            'size_bytes' => 10,
            'state' => ChunkState::PENDING,
        ];

        $chunks->create($attributes);

        $this->expectException(QueryException::class);

        $chunks->create($attributes);
    }
}
