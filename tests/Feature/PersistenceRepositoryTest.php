<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
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
        self::assertSame($directory->id, $directories->findChild(null, ' invoices ', 'local', 'tenant-a', 'owner-a')?->id);
        self::assertTrue($file->directory->is($directory));
        self::assertTrue($directory->files->contains('id', $file->id));
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

    public function test_directory_names_are_unique_within_parent_driver_and_owner_scope(): void
    {
        $repository = $this->app->make(DirectoryRepository::class);
        $attributes = [
            'tenant_id' => 'tenant-a',
            'owner_id' => 'owner-a',
            'name' => 'Invoices',
            'path_key' => 'invoices',
            'driver' => 'local',
        ];

        $first = $repository->create($attributes);

        self::assertSame($first->id, $repository->findChild(null, 'Invoices', 'local', 'tenant-a', 'owner-a')?->id);

        try {
            $repository->create([...$attributes, 'name' => 'invoices']);
            self::fail('Expected a duplicate normalized directory name to be rejected.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        $otherOwner = $repository->create([...$attributes, 'owner_id' => 'owner-b']);

        self::assertNotSame($first->id, $otherOwner->id);
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
        self::assertNull($repository->findScoped($first->id, 'tenant-b', 'owner-a'));
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

    public function test_direct_transfer_creation_normalizes_and_enforces_idempotency_scope(): void
    {
        $repository = $this->app->make(TransferRepository::class);
        $attributes = [
            'tenant_id' => 'tenant-a',
            'owner_id' => 'owner-a',
            'direction' => TransferDirection::UPLOAD,
            'driver' => 'local',
            'state' => TransferState::PENDING,
            'idempotency_key' => 'direct-create-key',
        ];

        $created = $repository->create($attributes);

        self::assertNotNull($created->idempotency_scope);
        self::assertSame(
            $created->id,
            $repository->findByIdempotencyKey('direct-create-key', 'tenant-a', 'owner-a')?->id,
        );

        $this->expectException(QueryException::class);

        $repository->create($attributes);
    }

    public function test_repository_save_cannot_bypass_the_transfer_state_machine(): void
    {
        $repository = $this->app->make(TransferRepository::class);
        $transfer = $repository->create([
            'direction' => TransferDirection::UPLOAD,
            'driver' => 'local',
            'state' => TransferState::PENDING,
            'total_bytes' => 12,
        ]);
        $transfer->state = TransferState::COMPLETED;

        $this->expectException(LogicException::class);

        $repository->save($transfer);
    }

    public function test_resume_lease_is_exclusive_expires_and_requires_its_own_token_to_release(): void
    {
        $repository = $this->app->make(TransferRepository::class);
        $transfer = $repository->create([
            'direction' => TransferDirection::UPLOAD,
            'driver' => 'local',
            'state' => TransferState::TRANSFERRING,
            'total_bytes' => 12,
        ]);
        $now = now();

        self::assertTrue($repository->claimResumeLease(
            $transfer->id,
            'worker-token-a',
            $now,
            $now->copy()->addSeconds(30),
        ));
        self::assertFalse($repository->claimResumeLease(
            $transfer->id,
            'worker-token-b',
            $now->copy()->addSecond(),
            $now->copy()->addSeconds(31),
        ));
        self::assertFalse($repository->releaseResumeLease($transfer->id, 'worker-token-b'));

        $afterExpiry = $now->copy()->addSeconds(31);

        self::assertTrue($repository->claimResumeLease(
            $transfer->id,
            'worker-token-b',
            $afterExpiry,
            $afterExpiry->copy()->addSeconds(30),
        ));
        self::assertFalse($repository->releaseResumeLease($transfer->id, 'worker-token-a'));
        self::assertTrue($repository->releaseResumeLease($transfer->id, 'worker-token-b'));
        self::assertNull($repository->findScoped($transfer->id, null, null)?->resume_lease_token);
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

    public function test_file_and_directory_soft_deletion_is_persisted(): void
    {
        $directories = $this->app->make(DirectoryRepository::class);
        $files = $this->app->make(StoredFileRepository::class);
        $directory = $directories->create([
            'tenant_id' => 'tenant-soft',
            'owner_id' => 'owner-soft',
            'name' => 'Archive',
            'path_key' => 'archive',
            'driver' => 'local',
        ]);
        $file = $files->create([
            'tenant_id' => 'tenant-soft',
            'owner_id' => 'owner-soft',
            'directory_id' => $directory->id,
            'driver' => 'local',
            'name' => 'old.zip',
            'size_bytes' => 25,
            'status' => 'available',
            'visibility' => ObjectVisibility::PRIVATE,
        ]);

        self::assertTrue($files->softDelete($file));
        self::assertTrue($directories->softDelete($directory));
        self::assertNull($files->findScoped($file->id, 'tenant-soft', 'owner-soft'));
        self::assertNull($directories->findScoped($directory->id, 'tenant-soft', 'owner-soft'));
        self::assertTrue(StoredFile::withTrashed()->findOrFail($file->id)->trashed());
        self::assertTrue(Directory::withTrashed()->findOrFail($directory->id)->trashed());
    }

    public function test_expired_transfer_query_is_bounded_and_excludes_terminal_states(): void
    {
        $repository = $this->app->make(TransferRepository::class);
        $cutoff = now();

        foreach ([1, 2, 3] as $minutes) {
            $repository->create([
                'direction' => TransferDirection::UPLOAD,
                'driver' => 'local',
                'state' => TransferState::PENDING,
                'total_bytes' => 10,
                'expires_at' => $cutoff->copy()->subMinutes($minutes),
            ]);
        }

        $terminal = $repository->create([
            'direction' => TransferDirection::UPLOAD,
            'driver' => 'local',
            'state' => TransferState::COMPLETED,
            'total_bytes' => 10,
            'expires_at' => $cutoff->copy()->subMinute(),
        ]);

        $expired = $repository->expiredBefore($cutoff, 2);

        self::assertCount(2, $expired);
        self::assertFalse($expired->contains('id', $terminal->id));
        self::assertTrue($expired[0]->expires_at <= $expired[1]->expires_at);
    }

    public function test_transaction_rollback_removes_partial_metadata(): void
    {
        $directories = $this->app->make(DirectoryRepository::class);

        try {
            DB::transaction(function () use ($directories): void {
                $directories->create([
                    'tenant_id' => 'tenant-rollback',
                    'owner_id' => 'owner-rollback',
                    'name' => 'Temporary',
                    'path_key' => 'temporary',
                    'driver' => 'local',
                ]);

                throw new RuntimeException('Force transaction rollback.');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('Force transaction rollback.', $exception->getMessage());
        }

        self::assertSame(0, Directory::query()->where('tenant_id', 'tenant-rollback')->count());
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
