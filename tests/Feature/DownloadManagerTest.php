<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ShamimStack\Storix\Contracts\Repositories\StoredFileRepository;
use ShamimStack\Storix\Contracts\StorageDriver;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\DTOs\DownloadRequest;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StorageObjectMetadata;
use ShamimStack\Storix\DTOs\WriteResult;
use ShamimStack\Storix\DTOs\WriteStreamRequest;
use ShamimStack\Storix\Exceptions\DownloadTooLargeException;
use ShamimStack\Storix\Exceptions\StorageOperationFailedException;
use ShamimStack\Storix\Exceptions\UnsupportedCapabilityException;
use ShamimStack\Storix\Exceptions\UploadIntegrityException;
use ShamimStack\Storix\Facades\Storix;
use ShamimStack\Storix\Models\StoredFile;
use ShamimStack\Storix\Tests\TestCase;

final class DownloadManagerTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/storix-e09-'.Str::uuid();
        config(['storix.drivers.local.root' => $this->root]);

        if (DIRECTORY_SEPARATOR === '\\') {
            $lockDirectory = $this->root.DIRECTORY_SEPARATOR.'locks';
            $downloadLockDirectory = $this->root.DIRECTORY_SEPARATOR.'download-locks';
            File::ensureDirectoryExists($lockDirectory, 0700, true);
            File::ensureDirectoryExists($downloadLockDirectory, 0700, true);
            config([
                'storix.downloads.lock_directory' => $lockDirectory,
                'storix.downloads.windows_lock_directory_trusted' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_streams_a_scoped_file_only_after_full_checksum_verification(): void
    {
        $contents = "Storix download\0payload";
        $file = $this->storeFile($contents);
        $result = Storix::downloads()->download(new DownloadRequest(
            fileId: (string) $file->id,
            tenantId: 'tenant-a',
            ownerId: 'owner-a',
        ));

        try {
            self::assertSame(strlen($contents), $result->contentLength);
            self::assertFalse($result->partial);
            self::assertTrue($result->checksumVerified);
            self::assertSame($contents, stream_get_contents($result->stream));
        } finally {
            $result->close();
        }
    }

    public function test_it_rejects_a_file_outside_the_requested_tenant_or_owner_scope(): void
    {
        $file = $this->storeFile('private bytes');

        $this->expectException(StorageOperationFailedException::class);
        Storix::downloads()->download(new DownloadRequest(
            fileId: (string) $file->id,
            tenantId: 'tenant-b',
            ownerId: 'owner-a',
        ));
    }

    public function test_it_rejects_unavailable_files_and_integrity_mismatches(): void
    {
        $file = $this->storeFile('immutable bytes');
        $file->status = 'processing';
        $file->save();

        try {
            Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a'));
            self::fail('An unavailable file must not be downloaded.');
        } catch (StorageOperationFailedException) {
            self::assertTrue(true);
        }

        $file->status = 'available';
        $file->save();
        file_put_contents($this->root.DIRECTORY_SEPARATOR.$file->storage_key, 'tampered_bytes!');

        $this->expectException(UploadIntegrityException::class);
        Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a'));
    }

    public function test_it_returns_native_byte_ranges_and_clamps_at_eof(): void
    {
        $contents = '0123456789abcdef';
        $file = $this->storeFile($contents);
        $result = Storix::downloads()->download(new DownloadRequest(
            fileId: (string) $file->id,
            tenantId: 'tenant-a',
            ownerId: 'owner-a',
            offset: 5,
            length: 99,
        ));

        try {
            self::assertTrue($result->partial);
            self::assertSame(5, $result->rangeStart);
            self::assertSame(strlen($contents) - 5, $result->contentLength);
            self::assertTrue($result->checksumVerified);
            self::assertSame(substr($contents, 5), stream_get_contents($result->stream));
        } finally {
            $result->close();
        }
    }

    public function test_it_rejects_ranges_for_drivers_without_range_capability(): void
    {
        $driver = $this->createMock(StorageDriver::class);
        $driver->method('name')->willReturn('no-range');
        $driver->method('capabilities')->willReturn(new DriverCapabilities(supportsRangeReads: false));
        $driver->method('writeStream')->willReturnCallback(static function (WriteStreamRequest $request): WriteResult {
            $contents = stream_get_contents($request->stream);

            return new WriteResult(new StorageObjectMetadata(
                key: $request->key,
                sizeBytes: strlen($contents),
                checksumSha256: hash('sha256', $contents),
            ));
        });
        $this->app->make(DriverManager::class)->register('no-range', $driver);
        $file = $this->storeFile('range disabled', 'no-range');

        $this->expectException(UnsupportedCapabilityException::class);
        Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a', 0, 3));
    }

    public function test_it_rejects_a_range_start_at_or_beyond_eof(): void
    {
        $file = $this->storeFile('short');

        $this->expectException(StorageOperationFailedException::class);
        Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a', 5, 1));
    }

    public function test_seekable_native_range_stream_is_verified_but_not_returned_to_the_caller(): void
    {
        $contents = '0123456789abcdef';
        $rangeContents = substr($contents, 4, 6);
        $rangeStream = null;
        $driver = $this->createMock(StorageDriver::class);
        $driver->method('name')->willReturn('seekable-range');
        $driver->method('capabilities')->willReturn(new DriverCapabilities(supportsRangeReads: true));
        $driver->method('stat')->willReturn(new StorageObjectMetadata(
            key: 'range/object.bin',
            sizeBytes: strlen($contents),
            checksumSha256: hash('sha256', $contents),
        ));
        $driver->method('readStream')->willReturnCallback(static function (ReadStreamRequest $request) use ($contents, $rangeContents, &$rangeStream) {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $request->offset === null ? $contents : $rangeContents);
            rewind($stream);

            if ($request->offset !== null) {
                $rangeStream = $stream;
            }

            return $stream;
        });
        $this->app->make(DriverManager::class)->register('seekable-range', $driver);

        $file = $this->app->make(StoredFileRepository::class)->create([
            'tenant_id' => 'tenant-a',
            'owner_id' => 'owner-a',
            'directory_id' => null,
            'driver' => 'seekable-range',
            'name' => 'object.bin',
            'original_name' => 'object.bin',
            'extension' => 'bin',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'storage_key' => 'range/object.bin',
            'status' => 'available',
            'visibility' => 'private',
            'metadata' => [],
        ]);

        $result = Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a', 4, 6));

        try {
            self::assertNotSame($rangeStream, $result->stream);
            self::assertFalse(is_resource($rangeStream));
            self::assertSame($rangeContents, stream_get_contents($result->stream));
        } finally {
            $result->close();
        }
    }

    public function test_download_result_destruction_closes_stream_and_releases_slot(): void
    {
        config([
            'storix.downloads.max_concurrent_downloads' => 1,
            'storix.downloads.lock_directory' => $this->root.DIRECTORY_SEPARATOR.'download-locks',
        ]);

        $file = $this->storeFile('destructor releases slot');
        $result = Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a'));
        $stream = $result->stream;
        unset($result);

        self::assertFalse(is_resource($stream));

        $next = Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a'));
        $next->close();
    }

    public function test_download_admission_lock_is_released_when_integrity_verification_fails(): void
    {
        config([
            'storix.downloads.max_concurrent_downloads' => 1,
            'storix.downloads.lock_directory' => $this->root.DIRECTORY_SEPARATOR.'download-locks',
        ]);

        $contents = 'verified payload';
        $file = $this->storeFile($contents);
        $path = $this->root.DIRECTORY_SEPARATOR.$file->storage_key;
        file_put_contents($path, str_repeat('x', strlen($contents)));

        try {
            Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a'));
            self::fail('Tampered bytes must fail integrity verification.');
        } catch (UploadIntegrityException) {
            self::assertTrue(true);
        }

        file_put_contents($path, $contents);
        $result = Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a'));

        try {
            self::assertSame($contents, stream_get_contents($result->stream));
        } finally {
            $result->close();
        }
    }

    public function test_download_admission_lock_limits_concurrency_and_releases_on_close(): void
    {
        config([
            'storix.downloads.max_concurrent_downloads' => 1,
            'storix.downloads.lock_directory' => $this->root.DIRECTORY_SEPARATOR.'download-locks',
        ]);

        $file = $this->storeFile('capacity controlled');
        $request = new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a');
        $first = Storix::downloads()->download($request);

        try {
            try {
                Storix::downloads()->download($request);
                self::fail('A second download must be rejected while the only slot is held.');
            } catch (StorageOperationFailedException $exception) {
                self::assertSame('download_capacity', $exception->operation);
            }
        } finally {
            $first->close();
            $first->close();
        }

        $next = Storix::downloads()->download($request);
        $next->close();
    }

    public function test_download_rejects_a_filesystem_root_as_lock_directory(): void
    {
        if (DIRECTORY_SEPARATOR === '\\\\') {
            self::markTestSkipped('POSIX filesystem-root path check is not available on Windows.');
        }

        config([
            'storix.downloads.max_concurrent_downloads' => 1,
            'storix.downloads.lock_directory' => DIRECTORY_SEPARATOR,
        ]);

        $file = $this->storeFile('filesystem root must not be a lock directory');

        try {
            Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a'));
            self::fail('The filesystem root must never be accepted as a download lock directory.');
        } catch (StorageOperationFailedException $exception) {
            self::assertSame('download_capacity_lock', $exception->operation);
        }
    }

    public function test_download_rejects_a_preexisting_slot_file_with_permissive_permissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX file permission checks are not available on Windows.');
        }

        $directory = $this->root.DIRECTORY_SEPARATOR.'private-download-locks';
        mkdir($directory, 0700, true);
        chmod($directory, 0700);
        $slot = $directory.DIRECTORY_SEPARATOR.'slot-0.lock';
        file_put_contents($slot, '');
        chmod($slot, 0644);

        config([
            'storix.downloads.max_concurrent_downloads' => 1,
            'storix.downloads.lock_directory' => $directory,
        ]);

        $file = $this->storeFile('private slot file required');

        try {
            Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a'));
            self::fail('A pre-existing slot file accessible by other users must be rejected.');
        } catch (StorageOperationFailedException $exception) {
            self::assertSame('download_capacity_lock', $exception->operation);
        }
    }

    public function test_download_rejects_a_lock_directory_below_a_writable_non_sticky_ancestor(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX directory permission checks are not available on Windows.');
        }

        $parent = $this->root.DIRECTORY_SEPARATOR.'writable-parent';
        mkdir($parent, 0700, true);
        chmod($parent, 0777);

        $directory = $parent.DIRECTORY_SEPARATOR.'private-download-locks';
        mkdir($directory, 0700);
        chmod($directory, 0700);

        config([
            'storix.downloads.max_concurrent_downloads' => 1,
            'storix.downloads.lock_directory' => $directory,
        ]);

        $file = $this->storeFile('trusted ancestors required');

        $this->expectException(StorageOperationFailedException::class);
        Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a'));
    }

    public function test_download_rejects_a_lock_directory_with_a_symlinked_ancestor(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX path-component checks are not available on Windows.');
        }

        $target = $this->root.DIRECTORY_SEPARATOR.'real-parent';
        mkdir($target, 0700, true);
        chmod($target, 0700);

        $link = $this->root.DIRECTORY_SEPARATOR.'linked-parent';
        symlink($target, $link);

        config([
            'storix.downloads.max_concurrent_downloads' => 1,
            'storix.downloads.lock_directory' => $link.DIRECTORY_SEPARATOR.'private-download-locks',
        ]);

        $file = $this->storeFile('symlink ancestors rejected');

        try {
            Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a'));
            self::fail('A symlinked lock-directory ancestor must be rejected.');
        } catch (StorageOperationFailedException $exception) {
            self::assertSame('download_capacity_lock', $exception->operation);
        }

        self::assertDirectoryDoesNotExist($target.DIRECTORY_SEPARATOR.'private-download-locks');
    }

    public function test_download_rejects_a_lock_directory_with_permissive_permissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('POSIX directory permission checks are not available on Windows.');
        }

        $directory = $this->root.DIRECTORY_SEPARATOR.'insecure-download-locks';
        mkdir($directory, 0755, true);
        chmod($directory, 0755);

        config([
            'storix.downloads.max_concurrent_downloads' => 1,
            'storix.downloads.lock_directory' => $directory,
        ]);

        $file = $this->storeFile('private lock directory required');

        try {
            Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a'));
            self::fail('A lock directory accessible by other users must be rejected.');
        } catch (StorageOperationFailedException $exception) {
            self::assertSame('download_capacity_lock', $exception->operation);
        }
    }

    public function test_download_size_limit_is_checked_before_reading_the_provider(): void
    {
        config(['storix.downloads.max_size_bytes' => 4]);

        $driver = $this->createMock(StorageDriver::class);
        $driver->method('name')->willReturn('size-guard');
        $driver->method('capabilities')->willReturn(new DriverCapabilities);
        $driver->method('stat')->willReturn(new StorageObjectMetadata(
            key: 'guarded/large.bin',
            sizeBytes: 5,
            checksumSha256: hash('sha256', '12345'),
        ));
        $driver->expects($this->never())->method('readStream');
        $this->app->make(DriverManager::class)->register('size-guard', $driver);

        $file = $this->app->make(StoredFileRepository::class)->create([
            'tenant_id' => 'tenant-a',
            'owner_id' => 'owner-a',
            'directory_id' => null,
            'driver' => 'size-guard',
            'name' => 'large.bin',
            'original_name' => 'large.bin',
            'extension' => 'bin',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 5,
            'sha256' => hash('sha256', '12345'),
            'storage_key' => 'guarded/large.bin',
            'status' => 'available',
            'visibility' => 'private',
            'metadata' => [],
        ]);

        $this->expectException(DownloadTooLargeException::class);
        Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a'));
    }

    public function test_out_of_bounds_range_is_rejected_before_reading_the_object(): void
    {
        $driver = $this->createMock(StorageDriver::class);
        $driver->method('name')->willReturn('range-guard');
        $driver->method('capabilities')->willReturn(new DriverCapabilities(supportsRangeReads: true));
        $driver->method('stat')->willReturn(new StorageObjectMetadata(
            key: 'guarded/object.bin',
            sizeBytes: 5,
            checksumSha256: hash('sha256', '12345'),
        ));
        $driver->expects($this->never())->method('readStream');
        $this->app->make(DriverManager::class)->register('range-guard', $driver);

        $file = $this->app->make(StoredFileRepository::class)->create([
            'tenant_id' => 'tenant-a',
            'owner_id' => 'owner-a',
            'directory_id' => null,
            'driver' => 'range-guard',
            'name' => 'object.bin',
            'original_name' => 'object.bin',
            'extension' => 'bin',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 5,
            'sha256' => hash('sha256', '12345'),
            'storage_key' => 'guarded/object.bin',
            'status' => 'available',
            'visibility' => 'private',
            'metadata' => [],
        ]);

        $this->expectException(StorageOperationFailedException::class);
        Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a', 5, 1));
    }

    public function test_windows_downloads_fail_closed_without_a_trusted_lock_path_opt_in(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('Windows lock-path policy is Windows-specific.');
        }

        $file = $this->storeFile('windows lock policy');
        config(['storix.downloads.windows_lock_directory_trusted' => false]);

        try {
            Storix::downloads()->download(new DownloadRequest((string) $file->id, 'tenant-a', 'owner-a'));
            self::fail('Windows downloads must fail closed until the lock path ACL boundary is explicitly trusted.');
        } catch (StorageOperationFailedException $exception) {
            self::assertSame('download_capacity_lock', $exception->operation);
        }
    }

    private function storeFile(string $contents, string $driverName = 'local'): StoredFile
    {
        $driver = $this->app->make(DriverManager::class)->driver($driverName);
        $key = 'downloads/'.Str::uuid().'.bin';
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        try {
            $driver->writeStream(new WriteStreamRequest($key, $stream));
        } finally {
            fclose($stream);
        }

        return $this->app->make(StoredFileRepository::class)->create([
            'tenant_id' => 'tenant-a',
            'owner_id' => 'owner-a',
            'directory_id' => null,
            'driver' => $driverName,
            'name' => basename($key),
            'original_name' => 'download.bin',
            'extension' => 'bin',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'storage_key' => $key,
            'status' => 'available',
            'visibility' => 'private',
            'metadata' => [],
        ]);
    }
}
