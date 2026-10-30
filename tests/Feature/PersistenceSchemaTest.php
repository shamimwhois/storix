<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ShamimStack\Storix\Contracts\Repositories\ChunkRepository;
use ShamimStack\Storix\Contracts\Repositories\DirectoryRepository;
use ShamimStack\Storix\Contracts\Repositories\StoredFileRepository;
use ShamimStack\Storix\Contracts\Repositories\TransferRepository;
use ShamimStack\Storix\Repositories\Eloquent\EloquentChunkRepository;
use ShamimStack\Storix\Repositories\Eloquent\EloquentDirectoryRepository;
use ShamimStack\Storix\Repositories\Eloquent\EloquentStoredFileRepository;
use ShamimStack\Storix\Repositories\Eloquent\EloquentTransferRepository;
use ShamimStack\Storix\Tests\TestCase;

final class PersistenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_e03_tables_are_migrated(): void
    {
        self::assertTrue(Schema::hasTable('storix_directories'));
        self::assertTrue(Schema::hasTable('storix_files'));
        self::assertTrue(Schema::hasTable('storix_transfers'));
        self::assertTrue(Schema::hasTable('storix_chunks'));
    }

    public function test_package_repository_contracts_are_bound(): void
    {
        self::assertInstanceOf(
            EloquentDirectoryRepository::class,
            $this->app->make(DirectoryRepository::class),
        );
        self::assertInstanceOf(
            EloquentStoredFileRepository::class,
            $this->app->make(StoredFileRepository::class),
        );
        self::assertInstanceOf(
            EloquentTransferRepository::class,
            $this->app->make(TransferRepository::class),
        );
        self::assertInstanceOf(
            EloquentChunkRepository::class,
            $this->app->make(ChunkRepository::class),
        );
    }
}
