<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Drivers\Local;

use DateTimeImmutable;
use DirectoryIterator;
use FilesystemIterator;
use finfo;
use InvalidArgumentException;
use ShamimStack\Storix\Contracts\DirectoryDriver;
use ShamimStack\Storix\Contracts\StorageDriver;
use ShamimStack\Storix\DTOs\DeleteObjectRequest;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StatObjectRequest;
use ShamimStack\Storix\DTOs\StorageDirectoryEntry;
use ShamimStack\Storix\DTOs\StorageObjectMetadata;
use ShamimStack\Storix\DTOs\WriteResult;
use ShamimStack\Storix\DTOs\WriteStreamRequest;
use ShamimStack\Storix\Exceptions\StorageOperationFailedException;
use Throwable;

final class LocalStorageDriver implements StorageDriver, DirectoryDriver
{
    private const int IO_CHUNK_BYTES = 1_048_576;

    private const int RANGE_MEMORY_LIMIT_BYTES = 2_097_152;

    private ?string $canonicalRoot = null;

    public function __construct(private readonly string $root)
    {
        if (trim($this->root) === '') {
            throw new InvalidArgumentException('The local storage root must not be empty.');
        }
    }

    public function name(): string
    {
        return 'local';
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(
            maxObjectSizeBytes: null,
            minChunkSizeBytes: 1,
            preferredChunkSizeBytes: 8_388_608,
            maxChunkSizeBytes: 67_108_864,
            maxConcurrency: 1,
            supportsStreaming: true,
            supportsRangeReads: true,
            supportsMultipartWrites: false,
            supportsServerSideCopy: false,
            supportsAtomicFinalize: true,
            supportsPauseResume: false,
        );
    }

    public function writeStream(WriteStreamRequest $request): WriteResult
    {
        $path = $this->resolvePath($request->key, createParents: true, createRoot: true);
        $directory = dirname($path);

        if (is_dir($path)) {
            throw new StorageOperationFailedException('write');
        }

        $temporaryPath = $directory.DIRECTORY_SEPARATOR.'.storix-'.bin2hex(random_bytes(16)).'.tmp';
        $destination = @fopen($temporaryPath, 'x+b');

        if ($destination === false) {
            throw new StorageOperationFailedException('write');
        }

        $bytesWritten = 0;
        $hash = hash_init('sha256');

        try {
            if (@chmod($temporaryPath, 0600) === false) {
                throw new StorageOperationFailedException('write');
            }

            while (! feof($request->stream)) {
                $chunk = @fread($request->stream, self::IO_CHUNK_BYTES);

                if ($chunk === false) {
                    throw new StorageOperationFailedException('write');
                }

                if ($chunk === '') {
                    if (feof($request->stream)) {
                        break;
                    }

                    throw new StorageOperationFailedException('write');
                }

                $this->writeAll($destination, $chunk);
                hash_update($hash, $chunk);
                $bytesWritten += strlen($chunk);
            }

            $flushed = @fflush($destination);
            $closed = @fclose($destination);
            $destination = null;

            if ($flushed === false || $closed === false) {
                throw new StorageOperationFailedException('write');
            }

            if (is_link($path) || @rename($temporaryPath, $path) === false) {
                throw new StorageOperationFailedException('write');
            }

            $checksum = hash_final($hash);
            $lastModified = $this->lastModified($path);

            return new WriteResult(new StorageObjectMetadata(
                key: $request->key,
                sizeBytes: $bytesWritten,
                mimeType: $request->mimeType ?? $this->detectMimeType($path),
                checksumSha256: $checksum,
                lastModified: $lastModified,
                visibility: $request->visibility,
                metadata: $request->metadata,
            ));
        } catch (Throwable $exception) {
            if ($exception instanceof StorageOperationFailedException) {
                throw $exception;
            }

            throw new StorageOperationFailedException('write', $exception);
        } finally {
            if (is_resource($destination)) {
                @fclose($destination);
            }

            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @return resource
     */
    public function readStream(ReadStreamRequest $request): mixed
    {
        $path = $this->resolvePath($request->key, createParents: false, createRoot: false);

        if ($path === null || ! is_file($path) || is_link($path)) {
            throw new StorageOperationFailedException('read');
        }

        $source = @fopen($path, 'rb');

        if ($source === false) {
            throw new StorageOperationFailedException('read');
        }

        if ($request->offset !== null && @fseek($source, $request->offset, SEEK_SET) !== 0) {
            @fclose($source);

            throw new StorageOperationFailedException('read');
        }

        if ($request->length === null) {
            return $source;
        }

        $range = @fopen('php://temp/maxmemory:'.self::RANGE_MEMORY_LIMIT_BYTES, 'w+b');

        if ($range === false) {
            @fclose($source);

            throw new StorageOperationFailedException('read');
        }

        try {
            $remaining = $request->length;

            while ($remaining > 0 && ! feof($source)) {
                $chunk = @fread($source, min(self::IO_CHUNK_BYTES, $remaining));

                if ($chunk === false) {
                    throw new StorageOperationFailedException('read');
                }

                if ($chunk === '') {
                    if (feof($source)) {
                        break;
                    }

                    throw new StorageOperationFailedException('read');
                }

                $this->writeAll($range, $chunk);
                $remaining -= strlen($chunk);
            }

            if (@rewind($range) === false) {
                throw new StorageOperationFailedException('read');
            }

            return $range;
        } catch (Throwable $exception) {
            @fclose($range);

            if ($exception instanceof StorageOperationFailedException) {
                throw $exception;
            }

            throw new StorageOperationFailedException('read', $exception);
        } finally {
            @fclose($source);
        }
    }

    public function delete(DeleteObjectRequest $request): void
    {
        $path = $this->resolvePath($request->key, createParents: false, createRoot: false);

        if ($path === null || (! file_exists($path) && ! is_link($path))) {
            return;
        }

        if (is_link($path) || ! is_file($path) || @unlink($path) === false) {
            throw new StorageOperationFailedException('delete');
        }
    }

    public function exists(string $key): bool
    {
        $path = $this->resolvePath($key, createParents: false, createRoot: false);

        return $path !== null && is_file($path) && ! is_link($path);
    }

    public function stat(StatObjectRequest $request): ?StorageObjectMetadata
    {
        $path = $this->resolvePath($request->key, createParents: false, createRoot: false);

        if ($path === null || ! is_file($path) || is_link($path)) {
            return null;
        }

        $size = @filesize($path);

        if ($size === false) {
            throw new StorageOperationFailedException('stat');
        }

        return new StorageObjectMetadata(
            key: $request->key,
            sizeBytes: $size,
            mimeType: $this->detectMimeType($path),
            lastModified: $this->lastModified($path),
        );
    }

    public function makeDirectory(string $key): void
    {
        if ($key === '') {
            $this->rootPath(create: true);

            return;
        }

        $path = $this->resolvePath($key, createParents: true, createRoot: true);

        if (is_dir($path)) {
            return;
        }

        if (file_exists($path) || @mkdir($path, 0770) === false) {
            throw new StorageOperationFailedException('create_directory');
        }
    }

    public function listDirectory(string $key = ''): array
    {
        $path = $key === ''
            ? $this->rootPath(create: true)
            : $this->resolvePath($key, createParents: false, createRoot: false);

        if ($path === null || ! is_dir($path)) {
            throw new StorageOperationFailedException('list_directory');
        }

        try {
            $entries = [];

            foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
                $name = $item->getFilename();

                if ($item->isLink() || preg_match('/^\.storix-[a-f0-9]{32}\.tmp$/', $name) === 1) {
                    continue;
                }

                if (! $item->isDir() && ! $item->isFile()) {
                    continue;
                }

                $childKey = $key === '' ? $name : $key.'/'.$name;
                $size = $item->isFile() ? $item->getSize() : 0;
                $modified = $item->getMTime();

                $entries[] = new StorageDirectoryEntry(
                    name: $name,
                    key: $childKey,
                    isDirectory: $item->isDir(),
                    sizeBytes: $size,
                    lastModified: new DateTimeImmutable('@'.$modified),
                );
            }

            usort($entries, static fn (StorageDirectoryEntry $left, StorageDirectoryEntry $right): int => strcmp($left->name, $right->name));

            return $entries;
        } catch (Throwable $exception) {
            if ($exception instanceof StorageOperationFailedException) {
                throw $exception;
            }

            throw new StorageOperationFailedException('list_directory', $exception);
        }
    }

    public function deleteDirectory(string $key, bool $recursive = false): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('The local storage root cannot be deleted.');
        }

        $path = $this->resolvePath($key, createParents: false, createRoot: false);

        if ($path === null || ! file_exists($path)) {
            return;
        }

        if (is_link($path) || ! is_dir($path)) {
            throw new StorageOperationFailedException('delete_directory');
        }

        if ($recursive) {
            $this->removeDirectoryTree($path);

            return;
        }

        if (@rmdir($path) === false) {
            throw new StorageOperationFailedException('delete_directory');
        }
    }

    private function rootPath(bool $create): ?string
    {
        if ($this->canonicalRoot !== null) {
            if (is_link($this->canonicalRoot) || ! is_dir($this->canonicalRoot)) {
                throw new StorageOperationFailedException('resolve_root');
            }

            return $this->canonicalRoot;
        }

        if (is_link($this->root)) {
            throw new StorageOperationFailedException('resolve_root');
        }

        if (! file_exists($this->root)) {
            if (! $create) {
                return null;
            }

            if (@mkdir($this->root, 0770, true) === false && ! is_dir($this->root)) {
                throw new StorageOperationFailedException('create_root');
            }
        }

        if (! is_dir($this->root)) {
            throw new StorageOperationFailedException('resolve_root');
        }

        $canonical = realpath($this->root);

        if ($canonical === false) {
            throw new StorageOperationFailedException('resolve_root');
        }

        $this->canonicalRoot = $canonical;

        return $this->canonicalRoot;
    }

    private function resolvePath(string $key, bool $createParents, bool $createRoot): ?string
    {
        $segments = $this->segments($key);
        $root = $this->rootPath($createRoot);

        if ($root === null) {
            return null;
        }

        $path = $root;

        foreach ($segments as $index => $segment) {
            $path .= DIRECTORY_SEPARATOR.$segment;
            $isLast = $index === array_key_last($segments);

            if (is_link($path)) {
                throw new StorageOperationFailedException('resolve_path');
            }

            if (file_exists($path)) {
                if (! $isLast && ! is_dir($path)) {
                    throw new StorageOperationFailedException('resolve_path');
                }

                continue;
            }

            if (! $isLast && $createParents) {
                if (@mkdir($path, 0770) === false && ! is_dir($path)) {
                    throw new StorageOperationFailedException('create_directory');
                }

                if (is_link($path) || ! is_dir($path)) {
                    throw new StorageOperationFailedException('resolve_path');
                }

                continue;
            }

            if (! $isLast) {
                $remaining = array_slice($segments, $index + 1);

                return $path.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $remaining);
            }
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function segments(string $key): array
    {
        if ($key === ''
            || str_starts_with($key, '/')
            || str_ends_with($key, '/')
            || str_contains($key, '\\')
            || preg_match('/[\x00-\x1F\x7F<>:"|?*]/', $key) === 1) {
            throw new InvalidArgumentException('Storage keys must be relative POSIX-style paths.');
        }

        $segments = explode('/', $key);

        foreach ($segments as $segment) {
            if ($segment === ''
                || $segment === '.'
                || $segment === '..'
                || rtrim($segment, '. ') !== $segment
                || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $segment) === 1) {
                throw new InvalidArgumentException('Storage keys contain an invalid path segment.');
            }
        }

        return $segments;
    }

    /**
     * @param resource $destination
     */
    private function writeAll(mixed $destination, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $written = @fwrite($destination, substr($contents, $offset));

            if ($written === false || $written === 0) {
                throw new StorageOperationFailedException('stream_write');
            }

            $offset += $written;
        }
    }

    private function detectMimeType(string $path): ?string
    {
        if (! class_exists(finfo::class)) {
            return null;
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($detected) ? $detected : null;
    }

    private function lastModified(string $path): ?DateTimeImmutable
    {
        $timestamp = @filemtime($path);

        return $timestamp === false ? null : new DateTimeImmutable('@'.$timestamp);
    }

    private function removeDirectoryTree(string $path): void
    {
        try {
            foreach (new DirectoryIterator($path) as $item) {
                if ($item->isDot()) {
                    continue;
                }

                $childPath = $item->getPathname();

                if ($item->isLink()) {
                    throw new StorageOperationFailedException('delete_directory');
                }

                if ($item->isDir()) {
                    $this->removeDirectoryTree($childPath);

                    continue;
                }

                if (! $item->isFile() || @unlink($childPath) === false) {
                    throw new StorageOperationFailedException('delete_directory');
                }
            }

            if (@rmdir($path) === false) {
                throw new StorageOperationFailedException('delete_directory');
            }
        } catch (Throwable $exception) {
            if ($exception instanceof StorageOperationFailedException) {
                throw $exception;
            }

            throw new StorageOperationFailedException('delete_directory', $exception);
        }
    }
}
