<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use InvalidArgumentException;
use ShamimStack\Storix\Contracts\DirectoryDriver;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\Drivers\Local\LocalStorageDriver;
use ShamimStack\Storix\DTOs\DeleteObjectRequest;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StatObjectRequest;
use ShamimStack\Storix\DTOs\WriteStreamRequest;
use ShamimStack\Storix\Enums\StorixErrorCode;
use ShamimStack\Storix\Exceptions\StorageOperationFailedException;
use ShamimStack\Storix\Facades\Storix;
use ShamimStack\Storix\Tests\TestCase;

final class LocalStorageDriverTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            $this->removePath($root);
        }

        parent::tearDown();
    }

    public function test_local_driver_is_registered_as_the_default_driver(): void
    {
        $root = $this->temporaryDirectory();
        config(['storix.drivers.local.root' => $root]);
        $this->app->forgetInstance(DriverManager::class);
        $this->app->forgetInstance('storix');

        $manager = $this->app->make(DriverManager::class);

        self::assertSame(['local'], $manager->names());
        self::assertInstanceOf(LocalStorageDriver::class, $manager->driver());
        self::assertSame($manager->driver(), Storix::driver());
    }

    public function test_it_writes_binary_streams_with_a_checksum_and_metadata(): void
    {
        $driver = $this->driver();
        $payload = random_bytes(2_500_123);
        $input = $this->inputStream($payload);

        $result = $driver->writeStream(new WriteStreamRequest(
            key: 'backups/large.bin',
            stream: $input,
            mimeType: 'application/octet-stream',
            metadata: ['source' => 'test'],
        ));

        self::assertSame(strlen($payload), $result->object->sizeBytes);
        self::assertSame(hash('sha256', $payload), $result->object->checksumSha256);
        self::assertSame('application/octet-stream', $result->object->mimeType);
        self::assertSame(['source' => 'test'], $result->object->metadata);
        self::assertSame('backups/large.bin', $result->object->key);
        self::assertTrue($driver->exists('backups/large.bin'));

        fclose($input);
    }

    public function test_it_replaces_an_existing_object_and_cleans_temporary_files(): void
    {
        $driver = $this->driver();

        $first = $this->inputStream('old content');
        $driver->writeStream(new WriteStreamRequest('reports/current.txt', $first));
        fclose($first);

        $second = $this->inputStream('new content');
        $driver->writeStream(new WriteStreamRequest('reports/current.txt', $second));
        fclose($second);

        $entries = $driver->listDirectory('reports');
        $read = $driver->readStream(new ReadStreamRequest('reports/current.txt'));

        self::assertCount(1, $entries);
        self::assertSame('current.txt', $entries[0]->name);
        self::assertSame('new content', stream_get_contents($read));

        fclose($read);
    }

    public function test_it_reads_full_streams_and_bounded_ranges(): void
    {
        $driver = $this->driver();
        $input = $this->inputStream('0123456789');
        $driver->writeStream(new WriteStreamRequest('numbers.txt', $input));
        fclose($input);

        $full = $driver->readStream(new ReadStreamRequest('numbers.txt', offset: 6));
        self::assertSame('6789', stream_get_contents($full));
        fclose($full);

        $range = $driver->readStream(new ReadStreamRequest('numbers.txt', offset: 3, length: 4));
        self::assertSame('3456', stream_get_contents($range));
        fclose($range);

        $shortRange = $driver->readStream(new ReadStreamRequest('numbers.txt', offset: 8, length: 20));
        self::assertSame('89', stream_get_contents($shortRange));
        fclose($shortRange);
    }

    public function test_it_reports_metadata_and_deletes_objects_idempotently(): void
    {
        $driver = $this->driver();
        $input = $this->inputStream('metadata payload');
        $driver->writeStream(new WriteStreamRequest('metadata.txt', $input));
        fclose($input);

        $metadata = $driver->stat(new StatObjectRequest('metadata.txt'));

        self::assertNotNull($metadata);
        self::assertSame(strlen('metadata payload'), $metadata->sizeBytes);
        self::assertNotNull($metadata->lastModified);

        $driver->delete(new DeleteObjectRequest('metadata.txt'));
        $driver->delete(new DeleteObjectRequest('metadata.txt'));

        self::assertFalse($driver->exists('metadata.txt'));
        self::assertNull($driver->stat(new StatObjectRequest('metadata.txt')));
    }

    public function test_it_supports_directory_creation_listing_and_safe_recursive_deletion(): void
    {
        $driver = $this->driver();

        self::assertInstanceOf(DirectoryDriver::class, $driver);

        $driver->makeDirectory('documents/releases');
        $input = $this->inputStream('release notes');
        $driver->writeStream(new WriteStreamRequest('documents/notes.txt', $input));
        fclose($input);

        $entries = $driver->listDirectory('documents');

        self::assertSame(['notes.txt', 'releases'], array_map(
            static fn ($entry): string => $entry->name,
            $entries,
        ));
        self::assertFalse($entries[0]->isDirectory);
        self::assertTrue($entries[1]->isDirectory);

        try {
            $driver->deleteDirectory('documents');
            self::fail('A non-empty directory must not be deleted without recursive mode.');
        } catch (StorageOperationFailedException $exception) {
            self::assertSame(StorixErrorCode::STORAGE_OPERATION_FAILED, $exception->errorCode);
        }

        $driver->deleteDirectory('documents', recursive: true);

        self::assertFalse($driver->exists('documents/notes.txt'));
        self::assertSame([], $driver->listDirectory());
    }

    public function test_it_rejects_deleting_the_storage_root(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->driver()->deleteDirectory('');
    }

    public function test_it_rejects_traversal_absolute_and_ambiguous_keys(): void
    {
        $driver = $this->driver();

        foreach ([
            '../escape.txt',
            'nested/../escape.txt',
            '/absolute.txt',
            'nested//file.txt',
            'nested\\file.txt',
            'C:/windows/system.ini',
            'trailing-dot./file.txt',
            'reserved/CON',
        ] as $key) {
            try {
                $driver->exists($key);
                self::fail("The key [{$key}] should have been rejected.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_it_does_not_follow_symbolic_links_inside_the_storage_root(): void
    {
        if (! function_exists('symlink')) {
            self::markTestSkipped('Symbolic links are not available in this environment.');
        }

        $driver = $this->driver();
        $root = $this->temporaryRoots[array_key_last($this->temporaryRoots)];
        $outside = $this->temporaryDirectory();
        $outsideFile = $outside.DIRECTORY_SEPARATOR.'secret.txt';
        file_put_contents($outsideFile, 'secret');

        $driver->makeDirectory('linked');

        if (@symlink($outsideFile, $root.DIRECTORY_SEPARATOR.'linked'.DIRECTORY_SEPARATOR.'secret.txt') === false) {
            self::markTestSkipped('The test environment does not allow creating symbolic links.');
        }

        $this->expectException(StorageOperationFailedException::class);

        $driver->readStream(new ReadStreamRequest('linked/secret.txt'));
    }

    public function test_filesystem_failures_use_safe_package_errors(): void
    {
        $invalidRoot = $this->temporaryDirectory();
        file_put_contents($invalidRoot, 'not a directory');
        $driver = new LocalStorageDriver($invalidRoot);
        $input = $this->inputStream('payload');

        try {
            $driver->writeStream(new WriteStreamRequest('file.txt', $input));
            self::fail('Writing below a regular file must fail.');
        } catch (StorageOperationFailedException $exception) {
            self::assertSame(StorixErrorCode::STORAGE_OPERATION_FAILED, $exception->errorCode);
            self::assertStringNotContainsString($invalidRoot, $exception->getMessage());
        } finally {
            fclose($input);
        }
    }

    public function test_local_capabilities_advertise_streaming_range_reads_and_atomic_finalize(): void
    {
        $capabilities = $this->driver()->capabilities();

        self::assertTrue($capabilities->supportsStreaming);
        self::assertTrue($capabilities->supportsRangeReads);
        self::assertTrue($capabilities->supportsAtomicFinalize);
        self::assertFalse($capabilities->supportsMultipartWrites);
        self::assertNull($capabilities->maxObjectSizeBytes);
    }

    private function driver(): LocalStorageDriver
    {
        return new LocalStorageDriver($this->temporaryDirectory());
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storix-test-'.bin2hex(random_bytes(8));
        $this->temporaryRoots[] = $path;

        return $path;
    }

    /**
     * @return resource
     */
    private function inputStream(string $contents): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $this->removePath($path.DIRECTORY_SEPARATOR.$name);
        }

        @rmdir($path);
    }
}
