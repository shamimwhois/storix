<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ShamimStack\Storix\Contracts\StorageDriver;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\Drivers\Local\LocalStorageDriver;
use ShamimStack\Storix\DTOs\BeginMultipartWriteRequest;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\DTOs\StatObjectRequest;
use ShamimStack\Storix\DTOs\WriteStreamRequest;
use ShamimStack\Storix\Enums\ObjectVisibility;
use ShamimStack\Storix\Exceptions\StorageOperationFailedException;
use ShamimStack\Storix\Enums\TransferDirection;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Models\StoredFile;
use ShamimStack\Storix\Models\Transfer;
use ShamimStack\Storix\Services\TransferRecoveryService;
use ShamimStack\Storix\Tests\TestCase;

final class TransferRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/storix-e08-'.Str::uuid();
        config(['storix.drivers.local.root' => $this->root]);
        $this->app->forgetInstance(LocalStorageDriver::class);
        $this->app->forgetInstance(DriverManager::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->removeDirectory($this->root);
        }

        parent::tearDown();
    }

    public function test_it_recovers_a_published_object_after_the_process_crashes_before_database_finalization(): void
    {
        $contents = 'completed-by-provider-before-database-commit';
        $file = $this->makeFile($contents, 'processing');
        $driver = $this->app->make(DriverManager::class)->driver('local');
        $stream = $this->stream($contents);

        try {
            $driver->writeStream(new WriteStreamRequest(
                key: $file->storage_key,
                stream: $stream,
            ));
        } finally {
            fclose($stream);
        }

        $transfer = $this->makeTransfer(
            $file,
            TransferState::VERIFYING,
            attempts: 1,
            lastErrorCode: 'STORAGE_OPERATION_FAILED',
        );

        $summary = $this->app->make(TransferRecoveryService::class)->recover(
            limit: 10,
            staleSeconds: 60,
        );

        $transfer->refresh();
        $file->refresh();

        self::assertSame(1, $summary['examined']);
        self::assertSame(1, $summary['recovered']);
        self::assertSame(TransferState::COMPLETED, $transfer->state);
        self::assertSame('available', $file->status);
        self::assertSame(strlen($contents), $transfer->transferred_bytes);
        self::assertSame(1, $transfer->completed_chunks);
        self::assertNull($transfer->multipart_upload_id);
        self::assertNull($transfer->next_retry_at);
        self::assertNull($transfer->resume_lease_token);
    }

    public function test_it_retains_a_session_identifier_until_failed_abort_cleanup_succeeds(): void
    {
        $contents = 'verified-final-object';
        $file = $this->makeFile($contents, 'processing');
        $driver = $this->app->make(DriverManager::class)->driver('local');
        $stream = $this->stream($contents);

        try {
            $driver->writeStream(new WriteStreamRequest(
                key: $file->storage_key,
                stream: $stream,
            ));
        } finally {
            fclose($stream);
        }

        $uploadId = $driver->beginMultipartWrite(new BeginMultipartWriteRequest(
            key: $file->storage_key,
        ));
        $workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storix-multipart-'.$uploadId;
        $unexpectedDirectory = $workspace.DIRECTORY_SEPARATOR.'unexpected-directory';
        mkdir($unexpectedDirectory);

        $transfer = $this->makeTransfer(
            $file,
            TransferState::VERIFYING,
            attempts: 1,
            lastErrorCode: 'STORAGE_OPERATION_FAILED',
        );
        $transfer->multipart_upload_id = $uploadId;
        $transfer->save();

        DB::table('storix_transfers')->where('id', $transfer->id)->update([
            'updated_at' => now()->subMinutes(10),
        ]);

        $recovered = $this->app->make(TransferRecoveryService::class)->recover(
            limit: 10,
            staleSeconds: 60,
        );

        $transfer->refresh();
        $file->refresh();

        self::assertSame(1, $recovered['recovered']);
        self::assertSame(TransferState::COMPLETED, $transfer->state);
        self::assertSame('available', $file->status);
        self::assertSame($uploadId, $transfer->multipart_upload_id);
        self::assertDirectoryExists($unexpectedDirectory);
        self::assertTrue($driver->exists($file->storage_key));

        rmdir($unexpectedDirectory);

        DB::table('storix_transfers')->where('id', $transfer->id)->update([
            'updated_at' => now()->subMinutes(10),
        ]);

        $cleaned = $this->app->make(TransferRecoveryService::class)->recover(
            limit: 10,
            staleSeconds: 60,
        );

        $transfer->refresh();
        $file->refresh();

        self::assertSame(1, $cleaned['cleaned']);
        self::assertSame(TransferState::COMPLETED, $transfer->state);
        self::assertNull($transfer->multipart_upload_id);
        self::assertSame('available', $file->status);
        self::assertTrue($driver->exists($file->storage_key));
        self::assertDirectoryDoesNotExist($workspace);
    }

    public function test_it_expires_a_stale_session_only_after_storage_cleanup(): void
    {
        $file = $this->makeFile('not-yet-published', 'processing');
        $transfer = $this->makeTransfer(
            $file,
            TransferState::TRANSFERRING,
            attempts: 1,
            expiresAt: now()->subMinute(),
        );

        $summary = $this->app->make(TransferRecoveryService::class)->recover(
            limit: 10,
            staleSeconds: 60,
        );

        $transfer->refresh();
        $file->refresh();

        self::assertSame(1, $summary['examined']);
        self::assertSame(1, $summary['expired']);
        self::assertSame(TransferState::EXPIRED, $transfer->state);
        self::assertSame('failed', $file->status);
        self::assertSame('TRANSFER_EXPIRED', $transfer->last_error_code);
        self::assertNull($transfer->resume_lease_token);
    }

    public function test_recovery_never_reads_or_deletes_a_file_outside_the_transfer_scope(): void
    {
        $contents = 'another-owners-object';
        $file = $this->makeFile($contents, 'processing');
        $driver = $this->app->make(DriverManager::class)->driver('local');
        $stream = $this->stream($contents);

        try {
            $driver->writeStream(new WriteStreamRequest(
                key: $file->storage_key,
                stream: $stream,
            ));
        } finally {
            fclose($stream);
        }

        $transfer = $this->makeTransfer(
            $file,
            TransferState::TRANSFERRING,
            attempts: 1,
            lastErrorCode: 'STORAGE_OPERATION_FAILED',
            expiresAt: now()->subMinute(),
        );

        DB::table('storix_transfers')->where('id', $transfer->id)->update([
            'tenant_id' => 'another-tenant',
            'updated_at' => now()->subMinutes(10),
        ]);

        $summary = $this->app->make(TransferRecoveryService::class)->recover(
            limit: 10,
            staleSeconds: 60,
        );

        $transfer->refresh();
        $file->refresh();

        self::assertSame(1, $summary['expired']);
        self::assertSame(TransferState::EXPIRED, $transfer->state);
        self::assertSame('processing', $file->status);
        self::assertTrue($driver->exists($file->storage_key));
    }

    public function test_it_does_not_retry_a_persisted_permanent_failure(): void
    {
        $file = $this->makeFile('permanent-failure', 'processing');
        $transfer = $this->makeTransfer(
            $file,
            TransferState::RETRY_WAIT,
            attempts: 1,
            lastErrorCode: 'UPLOAD_INTEGRITY_FAILED',
        );

        $summary = $this->app->make(TransferRecoveryService::class)->recover(
            limit: 10,
            staleSeconds: 60,
        );

        $transfer->refresh();
        $file->refresh();

        self::assertSame(1, $summary['failed']);
        self::assertSame(TransferState::FAILED, $transfer->state);
        self::assertSame('UPLOAD_INTEGRITY_FAILED', $transfer->last_error_code);
        self::assertSame('failed', $file->status);
        self::assertNull($transfer->next_retry_at);
    }

    public function test_it_schedules_retryable_failures_with_a_persisted_backoff_deadline(): void
    {
        $transfer = $this->makeTransfer(
            null,
            TransferState::RETRY_WAIT,
            attempts: 1,
            lastErrorCode: 'STORAGE_OPERATION_FAILED',
        );

        $summary = $this->app->make(TransferRecoveryService::class)->recover(
            limit: 10,
            staleSeconds: 60,
        );

        $transfer->refresh();

        self::assertSame(1, $summary['waiting']);
        self::assertSame(TransferState::RETRY_WAIT, $transfer->state);
        self::assertNotNull($transfer->next_retry_at);
        self::assertTrue($transfer->next_retry_at->isFuture());
        self::assertNull($transfer->resume_lease_token);
    }


    public function test_it_cleans_a_terminal_multipart_session_without_deleting_an_available_file(): void
    {
        $file = $this->makeFile('already-published', 'available');
        $driver = $this->app->make(DriverManager::class)->driver('local');
        $publishedStream = $this->stream('already-published');

        try {
            $driver->writeStream(new WriteStreamRequest(
                key: $file->storage_key,
                stream: $publishedStream,
            ));
        } finally {
            fclose($publishedStream);
        }

        $uploadId = $driver->beginMultipartWrite(new BeginMultipartWriteRequest(
            key: $file->storage_key,
        ));

        $transfer = $this->makeTransfer(
            $file,
            TransferState::FAILED,
            attempts: 1,
            lastErrorCode: 'STORAGE_OPERATION_FAILED',
        );
        $transfer->multipart_upload_id = $uploadId;
        $transfer->save();

        DB::table('storix_transfers')->where('id', $transfer->id)->update([
            'updated_at' => now()->subMinutes(10),
        ]);

        $summary = $this->app->make(TransferRecoveryService::class)->recover(
            limit: 10,
            staleSeconds: 60,
        );

        $transfer->refresh();
        $file->refresh();

        self::assertSame(1, $summary['examined']);
        self::assertSame(1, $summary['cleaned']);
        self::assertSame(TransferState::FAILED, $transfer->state);
        self::assertNull($transfer->multipart_upload_id);
        self::assertNull($transfer->resume_lease_token);
        self::assertSame('available', $file->status);
        self::assertTrue($driver->exists($file->storage_key));
        self::assertDirectoryDoesNotExist(sys_get_temp_dir().DIRECTORY_SEPARATOR.'storix-multipart-'.$uploadId);
    }

    public function test_it_schedules_a_bounded_retry_when_provider_metadata_lookup_fails(): void
    {
        $file = $this->makeFile('provider-read-failure', 'processing', 'test');
        $transfer = $this->makeTransfer(
            $file,
            TransferState::VERIFYING,
            attempts: 1,
            lastErrorCode: null,
            driver: 'test',
        );
        $driver = $this->createMock(StorageDriver::class);
        $driver->method('name')->willReturn('test');
        $driver->method('capabilities')->willReturn(new DriverCapabilities);
        $driver->expects(self::once())
            ->method('stat')
            ->willThrowException(new StorageOperationFailedException('stat'));
        $this->app->make(DriverManager::class)->register('test', $driver);

        $summary = $this->app->make(TransferRecoveryService::class)->recover(
            limit: 10,
            staleSeconds: 60,
        );

        $transfer->refresh();

        self::assertSame(1, $summary['examined']);
        self::assertSame(1, $summary['waiting']);
        self::assertSame(0, $summary['failed']);
        self::assertSame(TransferState::RETRY_WAIT, $transfer->state);
        self::assertSame('STORAGE_OPERATION_FAILED', $transfer->last_error_code);
        self::assertNotNull($transfer->next_retry_at);
        self::assertTrue($transfer->next_retry_at->isFuture());
        self::assertNull($transfer->resume_lease_token);
    }

    public function test_dry_run_does_not_acquire_a_lease_or_change_transfer_state(): void
    {
        $transfer = $this->makeTransfer(
            null,
            TransferState::RETRY_WAIT,
            attempts: 1,
            lastErrorCode: 'STORAGE_OPERATION_FAILED',
        );

        $summary = $this->app->make(TransferRecoveryService::class)->recover(
            limit: 10,
            staleSeconds: 60,
            dryRun: true,
        );

        $transfer->refresh();

        self::assertSame(1, $summary['examined']);
        self::assertSame(0, $summary['waiting']);
        self::assertSame(TransferState::RETRY_WAIT, $transfer->state);
        self::assertNull($transfer->resume_lease_token);
        self::assertNull($transfer->next_retry_at);
    }

    private function makeFile(string $contents, string $status, string $driver = 'local'): StoredFile
    {
        return StoredFile::query()->create([
            'tenant_id' => 'tenant-e08',
            'owner_id' => 'owner-e08',
            'directory_id' => null,
            'driver' => $driver,
            'name' => 'recovery.txt',
            'original_name' => 'recovery.txt',
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'storage_key' => 'recovery/'.Str::uuid().'.txt',
            'status' => $status,
            'visibility' => ObjectVisibility::PRIVATE,
            'metadata' => [],
        ]);
    }

    private function makeTransfer(
        ?StoredFile $file,
        TransferState $state,
        int $attempts,
        ?string $lastErrorCode = null,
        mixed $expiresAt = null,
        string $driver = 'local',
    ): Transfer {
        $transfer = Transfer::query()->create([
            'file_id' => $file?->id,
            'tenant_id' => 'tenant-e08',
            'owner_id' => 'owner-e08',
            'direction' => TransferDirection::UPLOAD,
            'driver' => $driver,
            'state' => $state,
            'source_name' => 'recovery.txt',
            'total_bytes' => $file?->size_bytes ?? 16,
            'transferred_bytes' => 0,
            'total_chunks' => 1,
            'completed_chunks' => 0,
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'attempts' => $attempts,
            'max_attempts' => 5,
            'last_error_code' => $lastErrorCode,
            'expires_at' => $expiresAt ?? now()->addDay(),
        ]);

        DB::table('storix_transfers')->where('id', $transfer->id)->update([
            'updated_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(20),
        ]);

        return $transfer->refresh();
    }

    /** @return resource */
    private function stream(string $contents): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeDirectory($path.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($path);
    }
}
