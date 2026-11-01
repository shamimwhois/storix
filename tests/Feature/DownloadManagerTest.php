<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ShamimStack\Storix\Contracts\StorageDriver;
use ShamimStack\Storix\DTOs\DownloadRequest;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\DTOs\StorageObjectMetadata;
use ShamimStack\Storix\DTOs\WriteResult;
use ShamimStack\Storix\DTOs\WriteStreamRequest;
use ShamimStack\Storix\Drivers\DriverManager;
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
        file_put_contents($this->root.DIRECTORY_SEPARATOR.$file->storage_key, 'tampered bytes');

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
        $driver = $this->createMock(\ShamimStack\Storix\Contracts\StorageDriver::class);
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

        $file = $this->app->make(\ShamimStack\Storix\Contracts\Repositories\StoredFileRepository::class)->create([
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

        return $this->app->make(\ShamimStack\Storix\Contracts\Repositories\StoredFileRepository::class)->create([
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
