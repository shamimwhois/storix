<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Drivers\Local;

use DateTimeImmutable;
use DirectoryIterator;
use FilesystemIterator;
use finfo;
use InvalidArgumentException;
use Normalizer;
use ShamimStack\Storix\Contracts\DirectoryDriver;
use ShamimStack\Storix\Contracts\MultipartStorageDriver;
use ShamimStack\Storix\DTOs\BeginMultipartWriteRequest;
use ShamimStack\Storix\DTOs\CompleteMultipartWriteRequest;
use ShamimStack\Storix\DTOs\DeleteObjectRequest;
use ShamimStack\Storix\DTOs\DriverCapabilities;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StatObjectRequest;
use ShamimStack\Storix\DTOs\StorageDirectoryEntry;
use ShamimStack\Storix\DTOs\StorageObjectMetadata;
use ShamimStack\Storix\DTOs\MultipartPartResult;
use ShamimStack\Storix\DTOs\WriteMultipartPartRequest;
use ShamimStack\Storix\DTOs\WriteResult;
use ShamimStack\Storix\DTOs\WriteStreamRequest;
use ShamimStack\Storix\Exceptions\StorageOperationFailedException;
use Throwable;

final class LocalStorageDriver implements DirectoryDriver, MultipartStorageDriver
{
    private const int IO_CHUNK_BYTES = 1_048_576;

    private const int RANGE_MEMORY_LIMIT_BYTES = 2_097_152;

    private ?string $canonicalRoot = null;

    /**
     * Create the local adapter for an isolated storage root.
     */
    public function __construct(private readonly string $root)
    {
        if (trim($this->root) === '') {
            throw new InvalidArgumentException('The local storage root must not be empty.');
        }
    }

    /**
     * Return the stable driver identifier used by Storix registration.
     */
    public function name(): string
    {
        return 'local';
    }

    /**
     * Describe the streaming and range-read capabilities of the local filesystem backend.
     */
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
            supportsMultipartWrites: true,
            supportsServerSideCopy: false,
            supportsAtomicFinalize: true,
            supportsPauseResume: false,
        );
    }

    /**
     * Create a private multipart workspace and bind it to the requested object key.
     */
    public function beginMultipartWrite(BeginMultipartWriteRequest $request): string
    {
        try {
            $uploadId = bin2hex(random_bytes(32));
        } catch (Throwable $exception) {
            throw new StorageOperationFailedException('multipart_begin', $exception);
        }

        $directory = $this->multipartDirectory($uploadId);

        if (@mkdir($directory, 0700) === false || is_link($directory) || is_dir($directory) === false) {
            throw new StorageOperationFailedException('multipart_begin');
        }

        $manifestPath = $directory.DIRECTORY_SEPARATOR.'object-key';
        $manifest = @fopen($manifestPath, 'x+b');

        if ($manifest === false) {
            @rmdir($directory);

            throw new StorageOperationFailedException('multipart_begin');
        }

        try {
            $this->writeAll($manifest, $request->key);

            $flushed = @fflush($manifest);
            $closed = @fclose($manifest);
            $manifest = null;

            if ($flushed === false || $closed === false) {
                throw new StorageOperationFailedException('multipart_begin');
            }

            if (DIRECTORY_SEPARATOR !== '\\') {
                @chmod($manifestPath, 0600);
            }

            return $uploadId;
        } catch (Throwable $exception) {
            if (is_resource($manifest)) {
                @fclose($manifest);
            }

            $this->removeMultipartWorkspace($uploadId);

            if ($exception instanceof StorageOperationFailedException) {
                throw $exception;
            }

            throw new StorageOperationFailedException('multipart_begin', $exception);
        }
    }

    /**
     * Stream one part to an exclusive private file while calculating its SHA-256 digest.
     */
    public function writeMultipartPart(WriteMultipartPartRequest $request): MultipartPartResult
    {
        $directory = $this->existingMultipartDirectory($request->uploadId);
        $path = $directory.DIRECTORY_SEPARATOR.'part-'.$request->partNumber.'.bin';

        if (file_exists($path) || is_link($path)) {
            throw new StorageOperationFailedException('multipart_write');
        }

        $destination = @fopen($path, 'x+b');

        if ($destination === false) {
            throw new StorageOperationFailedException('multipart_write');
        }

        $hash = hash_init('sha256');
        $size = 0;

        try {
            while (feof($request->stream) === false) {
                $buffer = @fread($request->stream, self::IO_CHUNK_BYTES);

                if ($buffer === false) {
                    throw new StorageOperationFailedException('multipart_write');
                }

                if ($buffer === '') {
                    if (feof($request->stream)) {
                        break;
                    }

                    throw new StorageOperationFailedException('multipart_write');
                }

                $this->writeAll($destination, $buffer);
                hash_update($hash, $buffer);
                $size += strlen($buffer);
            }

            $flushed = @fflush($destination);
            $closed = @fclose($destination);
            $destination = null;

            if ($flushed === false || $closed === false) {
                throw new StorageOperationFailedException('multipart_write');
            }

            if (DIRECTORY_SEPARATOR !== '\\') {
                @chmod($path, 0600);
            }

            return new MultipartPartResult(
                partNumber: $request->partNumber,
                sizeBytes: $size,
                sha256: hash_final($hash),
            );
        } catch (Throwable $exception) {
            if (is_resource($destination)) {
                @fclose($destination);
            }

            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }

            if ($exception instanceof StorageOperationFailedException) {
                throw $exception;
            }

            throw new StorageOperationFailedException('multipart_write', $exception);
        }
    }

    /**
     * Re-verify every part, assemble the object with bounded memory, and publish it atomically.
     */
    public function completeMultipartWrite(CompleteMultipartWriteRequest $request): WriteResult
    {
        $directory = $this->existingMultipartDirectory($request->uploadId);
        $assembled = @fopen('php://temp/maxmemory:'.self::RANGE_MEMORY_LIMIT_BYTES, 'w+b');

        if ($assembled === false) {
            $this->removeMultipartWorkspace($request->uploadId);

            throw new StorageOperationFailedException('multipart_complete');
        }

        try {
            $this->assertMultipartObjectKey($directory, $request->key);
            $totalSize = 0;
            $totalHash = hash_init('sha256');

            foreach ($request->parts as $part) {
                $partPath = $directory.DIRECTORY_SEPARATOR.'part-'.$part->partNumber.'.bin';

                if (is_link($partPath) || is_file($partPath) === false) {
                    throw new StorageOperationFailedException('multipart_verify');
                }

                $source = @fopen($partPath, 'rb');

                if ($source === false) {
                    throw new StorageOperationFailedException('multipart_verify');
                }

                $partSize = 0;
                $partHash = hash_init('sha256');

                try {
                    while (feof($source) === false) {
                        $buffer = @fread($source, self::IO_CHUNK_BYTES);

                        if ($buffer === false) {
                            throw new StorageOperationFailedException('multipart_verify');
                        }

                        if ($buffer === '') {
                            if (feof($source)) {
                                break;
                            }

                            throw new StorageOperationFailedException('multipart_verify');
                        }

                        $this->writeAll($assembled, $buffer);
                        hash_update($partHash, $buffer);
                        hash_update($totalHash, $buffer);
                        $partSize += strlen($buffer);
                        $totalSize += strlen($buffer);
                    }
                } finally {
                    @fclose($source);
                }

                if (
                    $partSize !== $part->sizeBytes
                    || hash_equals(strtolower($part->sha256), hash_final($partHash)) === false
                ) {
                    throw new StorageOperationFailedException('multipart_verify');
                }
            }

            if (
                $totalSize !== $request->expectedSizeBytes
                || hash_equals(strtolower($request->expectedSha256), hash_final($totalHash)) === false
            ) {
                throw new StorageOperationFailedException('multipart_verify');
            }

            if (@rewind($assembled) === false) {
                throw new StorageOperationFailedException('multipart_complete');
            }

            return $this->writeStream(new WriteStreamRequest(
                key: $request->key,
                stream: $assembled,
                mimeType: $request->mimeType,
                visibility: $request->visibility,
                metadata: $request->metadata,
            ));
        } catch (Throwable $exception) {
            if ($exception instanceof StorageOperationFailedException) {
                throw $exception;
            }

            throw new StorageOperationFailedException('multipart_complete', $exception);
        } finally {
            if (is_resource($assembled)) {
                @fclose($assembled);
            }

            $this->removeMultipartWorkspace($request->uploadId);
        }
    }

    /**
     * Abort is idempotent: an already-removed workspace needs no further action.
     */
    public function abortMultipartWrite(string $uploadId): void
    {
        $this->removeMultipartWorkspace($uploadId);
    }

    /**
     * Stream the caller's input into a private staging file and atomically publish the completed object.
     */
    public function writeStream(WriteStreamRequest $request): WriteResult
    {
        $path = $this->resolvePath($request->key, createParents: true, createRoot: true);
        $directory = dirname($path);

        if (is_dir($path)) {
            throw new StorageOperationFailedException('write');
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            try {
                $temporaryPath = $directory.DIRECTORY_SEPARATOR.'.storix-tmp-'.bin2hex(random_bytes(16));
            } catch (Throwable $exception) {
                throw new StorageOperationFailedException('write', $exception);
            }

            $destination = @fopen($temporaryPath, 'x+b');
        } else {
            $temporaryPath = @tempnam($directory, '.storix-tmp-');

            if ($temporaryPath === false) {
                throw new StorageOperationFailedException('write');
            }

            if (dirname($temporaryPath) !== $directory) {
                @unlink($temporaryPath);

                throw new StorageOperationFailedException('write');
            }

            $destination = @fopen($temporaryPath, 'r+b');
        }

        if ($destination === false) {
            if (isset($temporaryPath) && (file_exists($temporaryPath) || is_link($temporaryPath))) {
                @unlink($temporaryPath);
            }

            throw new StorageOperationFailedException('write');
        }

        $bytesWritten = 0;
        $hash = hash_init('sha256');

        try {
            while (feof($request->stream) === false) {
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
     * Open an object as a readable stream, optionally returning only the requested byte range.
     *
     * @return resource
     */
    public function readStream(ReadStreamRequest $request): mixed
    {
        $path = $this->resolvePath($request->key, createParents: false, createRoot: false);

        if ($path === null || is_file($path) === false || is_link($path)) {
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

            while ($remaining > 0 && feof($source) === false) {
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

    /**
     * Remove an object without treating an already-missing key as an error.
     */
    public function delete(DeleteObjectRequest $request): void
    {
        $path = $this->resolvePath($request->key, createParents: false, createRoot: false);

        if ($path === null || (file_exists($path) === false && is_link($path) === false)) {
            return;
        }

        if (is_link($path) || is_file($path) === false || @unlink($path) === false) {
            throw new StorageOperationFailedException('delete');
        }
    }

    /**
     * Determine whether a regular object exists at the supplied storage key.
     */
    public function exists(string $key): bool
    {
        $path = $this->resolvePath($key, createParents: false, createRoot: false);

        return $path !== null && is_file($path) && is_link($path) === false;
    }

    /**
     * Return the object's filesystem metadata, or null when the object is absent.
     */
    public function stat(StatObjectRequest $request): ?StorageObjectMetadata
    {
        $path = $this->resolvePath($request->key, createParents: false, createRoot: false);

        if ($path === null || is_file($path) === false || is_link($path)) {
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

    /**
     * Create a directory and any missing parent directories below the storage root.
     */
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

    /**
     * List and sort the immediate file and directory entries without following symbolic links.
     */
    public function listDirectory(string $key = ''): array
    {
        $path = $key === ''
            ? $this->rootPath(create: true)
            : $this->resolvePath($key, createParents: false, createRoot: false);

        if ($path === null || is_dir($path) === false) {
            throw new StorageOperationFailedException('list_directory');
        }

        try {
            $entries = [];

            foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
                $name = $item->getFilename();

                if ($item->isLink() || strncasecmp($name, '.storix-tmp-', 12) === 0) {
                    continue;
                }

                if ($item->isDir() === false && $item->isFile() === false) {
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

    /**
     * Remove a directory, requiring explicit opt-in before recursively deleting its contents.
     */
    public function deleteDirectory(string $key, bool $recursive = false): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('The local storage root cannot be deleted.');
        }

        $path = $this->resolvePath($key, createParents: false, createRoot: false);

        if ($path === null || file_exists($path) === false) {
            return;
        }

        if (is_link($path) || is_dir($path) === false) {
            throw new StorageOperationFailedException('delete_directory');
        }

        if ($recursive) {
            $this->assertTreeHasNoLinks($path);
            $this->removeDirectoryTree($path);

            return;
        }

        if (@rmdir($path) === false) {
            throw new StorageOperationFailedException('delete_directory');
        }
    }

    private function multipartDirectory(string $uploadId): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $uploadId) !== 1) {
            throw new InvalidArgumentException('Multipart upload identifier is invalid.');
        }

        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'storix-multipart-'.$uploadId;
    }

    private function existingMultipartDirectory(string $uploadId): string
    {
        $directory = $this->multipartDirectory($uploadId);

        if (is_link($directory) || is_dir($directory) === false) {
            throw new StorageOperationFailedException('multipart_workspace');
        }

        return $directory;
    }

    private function assertMultipartObjectKey(string $directory, string $key): void
    {
        $manifestPath = $directory.DIRECTORY_SEPARATOR.'object-key';

        if (is_link($manifestPath) || is_file($manifestPath) === false) {
            throw new StorageOperationFailedException('multipart_workspace');
        }

        $storedKey = @file_get_contents($manifestPath);

        if ($storedKey === false || hash_equals($storedKey, $key) === false) {
            throw new StorageOperationFailedException('multipart_workspace');
        }
    }

    private function removeMultipartWorkspace(string $uploadId): void
    {
        $directory = $this->multipartDirectory($uploadId);

        if (file_exists($directory) === false && is_link($directory) === false) {
            return;
        }

        if (is_link($directory) || is_dir($directory) === false) {
            throw new StorageOperationFailedException('multipart_cleanup');
        }

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            $path = $entry->getPathname();

            if (
                ($entry->isLink() || $entry->isFile()) === false
                || @unlink($path) === false
            ) {
                throw new StorageOperationFailedException('multipart_cleanup');
            }
        }

        if (@rmdir($directory) === false && is_dir($directory)) {
            throw new StorageOperationFailedException('multipart_cleanup');
        }
    }

    /**
     * Resolve and cache the canonical storage root, creating it only when the operation allows it.
     */
    private function rootPath(bool $create): ?string
    {
        if ($this->canonicalRoot !== null) {
            if (is_link($this->canonicalRoot)) {
                throw new StorageOperationFailedException('resolve_root');
            }

            if (is_dir($this->canonicalRoot)) {
                return $this->canonicalRoot;
            }

            $this->canonicalRoot = null;
        }

        if (is_link($this->root)) {
            throw new StorageOperationFailedException('resolve_root');
        }

        if (file_exists($this->root) === false) {
            if ($create === false) {
                return null;
            }

            if (@mkdir($this->root, 0770, true) === false && is_dir($this->root) === false) {
                throw new StorageOperationFailedException('create_root');
            }
        }

        if (is_dir($this->root) === false) {
            throw new StorageOperationFailedException('resolve_root');
        }

        $canonical = realpath($this->root);

        if ($canonical === false) {
            throw new StorageOperationFailedException('resolve_root');
        }

        $this->canonicalRoot = $canonical;

        return $this->canonicalRoot;
    }

    /**
     * Resolve a validated relative key beneath the storage root while rejecting symbolic-link components.
     */
    private function resolvePath(string $key, bool $createParents, bool $createRoot): ?string
    {
        $segments = $this->segments($key);
        $root = $this->rootPath($createRoot);

        if ($root === null) {
            return null;
        }

        $path = $root;

        foreach ($segments as $index => $segment) {
            $this->assertNoCaseCollision($path, $segment);
            $path .= DIRECTORY_SEPARATOR.$segment;
            $isLast = $index === array_key_last($segments);

            if (is_link($path)) {
                throw new StorageOperationFailedException('resolve_path');
            }

            if (file_exists($path)) {
                if ($isLast === false && is_dir($path) === false) {
                    if ($createParents === false) {
                        return null;
                    }

                    throw new StorageOperationFailedException('resolve_path');
                }

                continue;
            }

            if ($isLast === false && $createParents) {
                if (@mkdir($path, 0770) === false && is_dir($path) === false) {
                    throw new StorageOperationFailedException('create_directory');
                }

                if (is_link($path) || is_dir($path) === false) {
                    throw new StorageOperationFailedException('resolve_path');
                }

                continue;
            }

            if ($isLast === false) {
                $remaining = array_slice($segments, $index + 1);

                return $path.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $remaining);
            }
        }

        return $path;
    }

    /**
     * Validate a relative storage key and split it into safe POSIX-style path segments, including Windows device and console aliases.
     *
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
                || strncasecmp($segment, '.storix-tmp-', 12) === 0
                || rtrim($segment, '. ') !== $segment
                || preg_match('/^(CON|PRN|AUX|NUL|CONIN[$]|CONOUT[$]|COM(?:[1-9]|\\xC2[\\xB2\\xB3\\xB9])|LPT(?:[1-9]|\\xC2[\\xB2\\xB3\\xB9]))(?:\\..*)?$/i', $segment) === 1) {
                throw new InvalidArgumentException('Storage keys contain an invalid path segment.');
            }
        }

        return $segments;
    }

    /** @param resource $destination */
    /**
     * Write the complete buffer to a stream while handling partial writes.
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

    /**
     * Detect the object's MIME type when the PHP fileinfo extension is available.
     */
    private function detectMimeType(string $path): ?string
    {
        if (class_exists(finfo::class) === false) {
            return null;
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($detected) ? $detected : null;
    }

    /**
     * Convert the filesystem modification timestamp into an immutable date value.
     */
    private function lastModified(string $path): ?DateTimeImmutable
    {
        $timestamp = @filemtime($path);

        return $timestamp === false ? null : new DateTimeImmutable('@'.$timestamp);
    }

    /**
     * Reject an existing path segment that differs only by Unicode case folding from the requested key.
     */
    private function assertNoCaseCollision(string $directory, string $segment): void
    {
        try {
            foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
                $existingName = $item->getFilename();

                if ($existingName !== $segment && $this->caseFold($existingName) === $this->caseFold($segment)) {
                    throw new InvalidArgumentException('Storage keys must not differ only by letter case.');
                }
            }
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new StorageOperationFailedException('resolve_path', $exception);
        }
    }

    /**
     * Normalize and case-fold valid UTF-8 names while preserving support for arbitrary byte names.
     */
    private function caseFold(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            return strtolower($value);
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        if ($normalized === false) {
            throw new StorageOperationFailedException('resolve_path');
        }

        $folded = mb_convert_case($normalized, MB_CASE_FOLD, 'UTF-8');
        $foldedNormalized = Normalizer::normalize($folded, Normalizer::FORM_C);

        if ($foldedNormalized === false) {
            throw new StorageOperationFailedException('resolve_path');
        }

        return $foldedNormalized;
    }

    /**
     * Scan a directory tree without mutation and reject symbolic links before recursive deletion begins.
     */
    private function assertTreeHasNoLinks(string $path): void
    {
        try {
            foreach (new DirectoryIterator($path) as $item) {
                if ($item->isDot()) {
                    continue;
                }

                if ($item->isLink()) {
                    throw new StorageOperationFailedException('delete_directory');
                }

                if ($item->isDir()) {
                    $this->assertTreeHasNoLinks($item->getPathname());
                }
            }
        } catch (Throwable $exception) {
            if ($exception instanceof StorageOperationFailedException) {
                throw $exception;
            }

            throw new StorageOperationFailedException('delete_directory', $exception);
        }
    }

    /**
     * Recursively remove regular files and directories while aborting on symbolic links.
     */
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

                if ($item->isFile() === false || @unlink($childPath) === false) {
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
