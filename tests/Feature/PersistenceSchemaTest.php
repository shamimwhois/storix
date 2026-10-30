<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
            \ShamimStack\Storix\Repositories\Eloquent\EloquentDirectoryRepository::class,
            $this->app->make(\ShamimStack\Storix\Contracts\Repositories\DirectoryRepository::class),
        );
        self::assertInstanceOf(
            \ShamimStack\Storix\Repositories\Eloquent\EloquentStoredFileRepository::class,
            $this->app->make(\ShamimStack\Storix\Contracts\Repositories\StoredFileRepository::class),
        );
        self::assertInstanceOf(
            \ShamimStack\Storix\Repositories\Eloquent\EloquentTransferRepository::class,
            $this->app->make(\ShamimStack\Storix\Contracts\Repositories\TransferRepository::class),
        );
        self::assertInstanceOf(
            \ShamimStack\Storix\Repositories\Eloquent\EloquentChunkRepository::class,
            $this->app->make(\ShamimStack\Storix\Contracts\Repositories\ChunkRepository::class),
        );
    }
}
