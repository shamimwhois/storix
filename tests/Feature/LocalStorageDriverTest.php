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

    /**
     * Remove all per-test filesystem roots before delegating to the Testbench cleanup.
     */
    protected function tearDown(): void
    {
        foreach ($this->temporaryRoots as $root) {
            $this->removePath($root);
        }

        parent::tearDown();
    }

    /**
     * Verify that the service provider registers the local adapter and exposes it through the facade.
     */
    public function test_local_driver_is_registered_as_the_default_driver(): void
    {
        $root = $this->temporaryDirectory();
        config(['storix.drivers.local.root' => $root]);
        $this->app->forgetInstance(LocalStorageDriver::class);
        $this->app->forgetInstance(DriverManager::class);
        $this->app->forgetInstance('storix');

        $manager = $this->app->make(DriverManager::class);

        self::assertSame(['local'], $manager->names());
        self::assertInstanceOf(LocalStorageDriver::class, $manager->driver());
        Storix::clearResolvedInstance('storix');

        self::assertSame($manager->driver(), Storix::driver());
    }

    /**
     * Verify binary-safe streaming, byte counts, SHA-256, MIME metadata, and private file permissions.
     */
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

        if (DIRECTORY_SEPARATOR === '/') {
            $permissions = fileperms($this->temporaryRoots[array_key_last($this->temporaryRoots)].DIRECTORY_SEPARATOR.'backups'.DIRECTORY_SEPARATOR.'large.bin');
            self::assertNotFalse($permissions);
            self::assertSame(0600, $permissions & 0777);
        }

        fclose($input);
    }

    /**
     * Verify that writes start at the caller's current stream position and leave the source open.
     */
    public function test_writes_from_the_current_input_position_without_closing_the_source(): void
    {
        $driver = $this->driver();
        $input = $this->inputStream('prefix-payload');

        fseek($input, strlen('prefix-'));

        $result = $driver->writeStream(new WriteStreamRequest('position.txt', $input));

        self::assertSame(strlen('payload'), $result->object->sizeBytes);
        self::assertSame('payload', file_get_contents($this->temporaryRoots[array_key_last($this->temporaryRoots)].DIRECTORY_SEPARATOR.'position.txt'));
        self::assertIsResource($input);

        fclose($input);
    }

    /**
     * Verify successful replacement publishes only the completed object and hides staging files.
     */
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

    /**
     * Verify offset reads and bounded reads return exactly the requested bytes.
     */
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

    /**
     * Verify stat metadata and that deleting a missing object remains safe.
     */
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

    /**
     * Verify lookup operations treat regular-file parents as missing while writes remain invalid.
     */
    public function test_lookup_operations_treat_a_regular_file_parent_as_missing(): void
    {
        $driver = $this->driver();
        $input = $this->inputStream('parent object');
        $driver->writeStream(new WriteStreamRequest('parent.txt', $input));
        fclose($input);

        self::assertFalse($driver->exists('parent.txt/child.txt'));
        self::assertNull($driver->stat(new StatObjectRequest('parent.txt/child.txt')));

        $driver->delete(new DeleteObjectRequest('parent.txt/child.txt'));

        self::assertTrue($driver->exists('parent.txt'));

        $childInput = $this->inputStream('child object');

        try {
            $driver->writeStream(new WriteStreamRequest('parent.txt/child.txt', $childInput));
            self::fail('Writing below a regular file must fail.');
        } catch (StorageOperationFailedException $exception) {
            self::assertSame(StorixErrorCode::STORAGE_OPERATION_FAILED, $exception->errorCode);
        } finally {
            fclose($childInput);
        }
    }

    /**
     * Verify that existing directory and object names cannot be shadowed by case-only aliases.
     */
    public function test_it_rejects_case_colliding_existing_path_segments(): void
    {
        $driver = $this->driver();
        $driver->makeDirectory('Docs');

        $directoryCollisionInput = $this->inputStream('directory collision');

        try {
            $driver->writeStream(new WriteStreamRequest('docs/file.txt', $directoryCollisionInput));
            self::fail('A directory key that differs only by letter case must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        } finally {
            fclose($directoryCollisionInput);
        }

        $objectInput = $this->inputStream('existing object');
        $driver->writeStream(new WriteStreamRequest('reports/Readme.txt', $objectInput));
        fclose($objectInput);

        try {
            $driver->exists('reports/README.txt');
            self::fail('An object key that differs only by letter case must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $driver->makeDirectory('Ärger');

        try {
            $driver->exists('ärger/file.txt');
            self::fail('Unicode path segments that differ only by letter case must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    /**
     * Verify sorted immediate listings and the explicit recursive-delete safety boundary.
     */
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

    /**
     * Verify that callers cannot delete the configured storage root.
     */
    public function test_it_rejects_deleting_the_storage_root(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->driver()->deleteDirectory('');
    }

    /**
     * Verify rejection of traversal, absolute, reserved, and malformed storage keys.
     */
    public function test_it_rejects_traversal_absolute_and_ambiguous_keys(): void
    {
        $driver = $this->driver();
        $invalidUtf8ReservedName = 'reserved/CON.'.chr(255);

        foreach ([
            '../escape.txt',
            'nested/../escape.txt',
            '/absolute.txt',
            'nested//file.txt',
            'nested\\file.txt',
            'C:/windows/system.ini',
            'trailing-dot./file.txt',
            'reserved/CON',
            'reserved/CON.txt',
            'reserved/NUL.log',
            'reserved/CONIN$',
            'reserved/CONOUT$',
            'reserved/CONIN$.txt',
            'reserved/COM¹',
            'reserved/COM².txt',
            'reserved/LPT³.log',
            $invalidUtf8ReservedName,
            '.storix-tmp-private/object.bin',
        ] as $key) {
            try {
                $driver->exists($key);
                self::fail("The key [{$key}] should have been rejected.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        self::assertFalse($driver->exists('reserved/file'.chr(255)));
    }

    /**
     * Verify that a symbolic link used as an intermediate directory is rejected during path resolution.
     */
    public function test_it_rejects_symlinked_intermediate_directories(): void
    {
        if (function_exists('symlink') === false) {
            self::markTestSkipped('Symbolic links are not available in this environment.');
        }

        $driver = $this->driver();
        $root = $this->temporaryRoots[array_key_last($this->temporaryRoots)];
        mkdir($root, 0770, true);

        $outside = $this->temporaryDirectory();
        mkdir($outside, 0770, true);
        $outsideFile = $outside.DIRECTORY_SEPARATOR.'secret.txt';
        file_put_contents($outsideFile, 'outside secret');

        if (@symlink($outside, $root.DIRECTORY_SEPARATOR.'linked-dir') === false) {
            self::markTestSkipped('The test environment does not allow creating symbolic links.');
        }

        $this->expectException(StorageOperationFailedException::class);

        $driver->readStream(new ReadStreamRequest('linked-dir/secret.txt'));
    }

    /**
     * Verify that listings omit symlinks and recursive deletion never removes their targets.
     */
    public function test_directory_listing_and_recursive_delete_do_not_follow_symlinks(): void
    {
        if (function_exists('symlink') === false) {
            self::markTestSkipped('Symbolic links are not available in this environment.');
        }

        $driver = $this->driver();
        $root = $this->temporaryRoots[array_key_last($this->temporaryRoots)];
        $outside = $this->temporaryDirectory();
        mkdir($outside, 0770, true);

        $outsideFile = $outside.DIRECTORY_SEPARATOR.'secret.txt';
        file_put_contents($outsideFile, 'outside secret');

        $driver->makeDirectory('documents');
        $input = $this->inputStream('document');
        $driver->writeStream(new WriteStreamRequest('documents/notes.txt', $input));
        fclose($input);

        if (@symlink($outsideFile, $root.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'external.txt') === false) {
            self::markTestSkipped('The test environment does not allow creating symbolic links.');
        }

        $entries = $driver->listDirectory('documents');

        self::assertSame(['notes.txt'], array_map(
            static fn ($entry): string => $entry->name,
            $entries,
        ));

        try {
            $driver->deleteDirectory('documents', recursive: true);
            self::fail('Recursive deletion must stop when a symbolic link is present.');
        } catch (StorageOperationFailedException $exception) {
            self::assertSame(StorixErrorCode::STORAGE_OPERATION_FAILED, $exception->errorCode);
        }

        self::assertFileExists($outsideFile);
        self::assertFileExists($root.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'notes.txt');
        self::assertFileExists($root.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'external.txt');
    }

    /**
     * Verify that a symlink pointing outside the root cannot be read as an object.
     */
    public function test_it_does_not_follow_symbolic_links_inside_the_storage_root(): void
    {
        if (function_exists('symlink') === false) {
            self::markTestSkipped('Symbolic links are not available in this environment.');
        }

        $driver = $this->driver();
        $root = $this->temporaryRoots[array_key_last($this->temporaryRoots)];
        $outside = $this->temporaryDirectory();
        mkdir($outside, 0770, true);
        $outsideFile = $outside.DIRECTORY_SEPARATOR.'secret.txt';
        file_put_contents($outsideFile, 'secret');

        $driver->makeDirectory('linked');

        if (@symlink($outsideFile, $root.DIRECTORY_SEPARATOR.'linked'.DIRECTORY_SEPARATOR.'secret.txt') === false) {
            self::markTestSkipped('The test environment does not allow creating symbolic links.');
        }

        $this->expectException(StorageOperationFailedException::class);

        $driver->readStream(new ReadStreamRequest('linked/secret.txt'));
    }

    /**
     * Verify filesystem failures map to a stable package error without leaking the absolute root path.
     */
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

    /**
     * Verify the driver advertises only the capabilities it actually implements.
     */
    public function test_local_capabilities_advertise_streaming_range_reads_and_atomic_finalize(): void
    {
        $capabilities = $this->driver()->capabilities();

        self::assertTrue($capabilities->supportsStreaming);
        self::assertTrue($capabilities->supportsRangeReads);
        self::assertTrue($capabilities->supportsAtomicFinalize);
        self::assertFalse($capabilities->supportsMultipartWrites);
        self::assertNull($capabilities->maxObjectSizeBytes);
    }

    /**
     * Create an isolated local driver using a fresh temporary root.
     */
    private function driver(): LocalStorageDriver
    {
        return new LocalStorageDriver($this->temporaryDirectory());
    }

    /**
     * Reserve a unique per-test path and register it for teardown cleanup.
     */
    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storix-test-'.bin2hex(random_bytes(8));
        $this->temporaryRoots[] = $path;

        return $path;
    }

    /**
     * Create a rewound in-memory stream containing the supplied test payload.
     *
     * @return resource
     */
    private function inputStream(string $contents): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * Remove a test filesystem path without traversing symbolic links.
     */
    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (is_dir($path) === false) {
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
