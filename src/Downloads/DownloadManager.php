<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Downloads;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ShamimStack\Storix\Contracts\Repositories\StoredFileRepository;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\DTOs\DownloadRequest;
use ShamimStack\Storix\DTOs\DownloadResult;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StatObjectRequest;
use ShamimStack\Storix\Exceptions\DownloadTooLargeException;
use ShamimStack\Storix\Exceptions\StorageOperationFailedException;
use ShamimStack\Storix\Exceptions\UnsupportedCapabilityException;
use ShamimStack\Storix\Exceptions\UploadIntegrityException;
use Throwable;

final class DownloadManager
{
    private const int IO_CHUNK_BYTES = 1_048_576;

    public function __construct(
        private readonly DriverManager $drivers,
        private readonly StoredFileRepository $files,
        private readonly ConfigRepository $config,
    ) {}

    public function download(DownloadRequest $request): DownloadResult
    {
        $file = $this->files->findScoped($request->fileId, $request->tenantId, $request->ownerId);

        if (
            $file === null
            || $file->status !== 'available'
            || is_string($file->storage_key) === false
            || $file->storage_key === ''
            || is_string($file->driver) === false
            || $file->driver === ''
        ) {
            throw new StorageOperationFailedException('download');
        }

        $driver = $this->drivers->driver($file->driver);
        $capabilities = $driver->capabilities();

        if ($capabilities->supportsStreaming === false) {
            throw new UnsupportedCapabilityException('streaming', $driver->name());
        }

        if ($request->isRangeRequest() && $capabilities->supportsRangeReads === false) {
            throw new UnsupportedCapabilityException('range_reads', $driver->name());
        }

        $metadata = $driver->stat(new StatObjectRequest((string) $file->storage_key));

        if (
            $metadata === null
            || $metadata->sizeBytes !== (int) $file->size_bytes
            || preg_match('/^[a-f0-9]{64}$/i', (string) $file->sha256) !== 1
        ) {
            throw new UploadIntegrityException;
        }

        $maximumBytes = $this->config->get('storix.downloads.max_size_bytes', 1024 * 1024 * 1024);

        if ($maximumBytes !== null && $metadata->sizeBytes > (int) $maximumBytes) {
            throw new DownloadTooLargeException((int) $maximumBytes);
        }

        if ($request->isRangeRequest() && (int) $request->offset >= $metadata->sizeBytes) {
            throw new StorageOperationFailedException('download_range_bounds');
        }

        $lock = $this->acquireDownloadSlot();
        $lockTransferred = false;
        $verified = null;
        $range = null;

        try {
            $verified = $this->stageAndVerify(
                $driver->readStream(new ReadStreamRequest((string) $file->storage_key)),
                (int) $file->size_bytes,
                (string) $file->sha256,
            );

            if ($request->isRangeRequest() === false) {
                rewind($verified['stream']);
                $result = new DownloadResult(
                    $file,
                    $verified['stream'],
                    $verified['size'],
                    0,
                    false,
                    true,
                    $this->lockReleaseCallback($lock),
                );
                $verified = null;
                $lockTransferred = true;

                return $result;
            }

            $offset = (int) $request->offset;
            $length = min((int) $request->length, $verified['size'] - $offset);
            $expected = $this->extractRange($verified['stream'], $offset, $length);
            $providerRange = $this->verifyRangeStream(
                $driver->readStream(new ReadStreamRequest(
                    key: (string) $file->storage_key,
                    offset: $offset,
                    length: $length,
                )),
                $length,
                $expected['sha256'],
            );

            if ($providerRange['size'] !== $length) {
                throw new UploadIntegrityException;
            }

            $range = $this->copyRange($verified['stream'], $offset, $length, $expected['sha256']);
            fclose($verified['stream']);
            $verified = null;

            $result = new DownloadResult(
                $file,
                $range['stream'],
                $length,
                $offset,
                true,
                true,
                $this->lockReleaseCallback($lock),
            );
            $range = null;
            $lockTransferred = true;

            return $result;
        } catch (Throwable $exception) {
            if (is_array($verified) && is_resource($verified['stream'])) {
                fclose($verified['stream']);
            }

            if (is_array($range) && is_resource($range['stream'])) {
                fclose($range['stream']);
            }

            throw $exception;
        } finally {
            if ($lockTransferred === false && is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /** @return resource */
    private function acquireDownloadSlot(): mixed
    {
        $maximumConcurrent = min(100, max(1, (int) $this->config->get('storix.downloads.max_concurrent_downloads', 1)));
        $directory = (string) $this->config->get(
            'storix.downloads.lock_directory',
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'storix-download-locks',
        );

        if (trim($directory) === '') {
            throw new StorageOperationFailedException('download_capacity_lock');
        }

        if (DIRECTORY_SEPARATOR === '\\' && is_dir($directory) === false) {
            // On Windows, require an operator-created directory so recursive mkdir
            // cannot traverse an unvalidated junction before path checks run.
            throw new StorageOperationFailedException('download_capacity_lock');
        }

        if (is_dir($directory) === false) {
            if (DIRECTORY_SEPARATOR === '\\\\') {
                throw new StorageOperationFailedException('download_capacity_lock');
            }

            $parent = dirname($directory);
            $this->assertTrustedLockDirectoryParent($parent);

            // Create only the leaf after validating its existing parent chain.
            // Recursive mkdir could follow a symlink in a missing path's ancestors.
            if (@mkdir($directory, 0700) === false && is_dir($directory) === false) {
                throw new StorageOperationFailedException('download_capacity_lock');
            }
        }

        if (is_link($directory) || is_dir($directory) === false) {
            throw new StorageOperationFailedException('download_capacity_lock');
        }

        $this->assertTrustedLockDirectory($directory);

        for ($slot = 0; $slot < $maximumConcurrent; $slot++) {
            $path = $directory.DIRECTORY_SEPARATOR.'slot-'.$slot.'.lock';

            if (is_link($path)) {
                throw new StorageOperationFailedException('download_capacity_lock');
            }

            $previousUmask = DIRECTORY_SEPARATOR !== '\\' ? umask(0077) : null;

            try {
                $handle = @fopen($path, 'c+b');
            } finally {
                if ($previousUmask !== null) {
                    umask($previousUmask);
                }
            }

            if ($handle === false) {
                throw new StorageOperationFailedException('download_capacity_lock');
            }

            $handleStat = @fstat($handle);
            $pathStat = @lstat($path);
            $effectiveUser = function_exists('posix_geteuid') ? posix_geteuid() : null;

            if (
                $handleStat === false
                || $pathStat === false
                || $handleStat['dev'] !== $pathStat['dev']
                || $handleStat['ino'] !== $pathStat['ino']
                || ($handleStat['mode'] & 0170000) !== 0100000
                || ($effectiveUser !== null && $handleStat['uid'] !== $effectiveUser)
                || (DIRECTORY_SEPARATOR !== '\\' && ($handleStat['mode'] & 0077) !== 0)
            ) {
                @fclose($handle);

                throw new StorageOperationFailedException('download_capacity_lock');
            }

            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }

            @fclose($handle);
        }

        throw new StorageOperationFailedException('download_capacity');
    }

    /**
     * Validate an existing POSIX parent chain before creating the lock-directory leaf.
     */
    private function assertTrustedLockDirectoryParent(string $directory): void
    {
        $absolute = str_starts_with($directory, DIRECTORY_SEPARATOR)
            ? $directory
            : getcwd().DIRECTORY_SEPARATOR.$directory;
        $segments = array_values(array_filter(
            explode(DIRECTORY_SEPARATOR, $absolute),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));
        $current = DIRECTORY_SEPARATOR;
        $effectiveUser = function_exists('posix_geteuid') ? posix_geteuid() : null;

        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new StorageOperationFailedException('download_capacity_lock');
            }

            $current = rtrim($current, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$segment;
            $stat = @lstat($current);

            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
                throw new StorageOperationFailedException('download_capacity_lock');
            }

            if ($effectiveUser !== null && $stat['uid'] !== $effectiveUser && $stat['uid'] !== 0) {
                throw new StorageOperationFailedException('download_capacity_lock');
            }

            $writableByOtherUsers = ($stat['mode'] & 0022) !== 0;
            $sticky = ($stat['mode'] & 01000) !== 0;

            if ($writableByOtherUsers && $sticky === false) {
                throw new StorageOperationFailedException('download_capacity_lock');
            }
        }
    }

    /**
     * Reject writable, non-sticky ancestors so another local user cannot replace
     * the validated lock directory between lstat() and opening a slot file.
     */
    private function assertTrustedLockDirectory(string $directory): void
    /**
     * Reject writable, non-sticky ancestors so another local user cannot replace
     * the validated lock directory between lstat() and opening a slot file.
     */
    private function assertTrustedLockDirectory(string $directory): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->assertTrustedWindowsLockDirectory($directory);

            return;
        }

        $absolute = str_starts_with($directory, DIRECTORY_SEPARATOR)
            ? $directory
            : getcwd().DIRECTORY_SEPARATOR.$directory;
        $segments = array_values(array_filter(
            explode(DIRECTORY_SEPARATOR, $absolute),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));
        $current = DIRECTORY_SEPARATOR;
        $effectiveUser = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $lastIndex = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            if ($segment === '..') {
                throw new StorageOperationFailedException('download_capacity_lock');
            }

            $current = rtrim($current, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$segment;
            $stat = @lstat($current);

            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
                throw new StorageOperationFailedException('download_capacity_lock');
            }

            if ($index === $lastIndex) {
                if (
                    ($stat['mode'] & 0077) !== 0
                    || ($effectiveUser !== null && $stat['uid'] !== $effectiveUser)
                ) {
                    throw new StorageOperationFailedException('download_capacity_lock');
                }

                continue;
            }

            if ($effectiveUser !== null && $stat['uid'] !== $effectiveUser && $stat['uid'] !== 0) {
                throw new StorageOperationFailedException('download_capacity_lock');
            }

            $writableByOtherUsers = ($stat['mode'] & 0022) !== 0;
            $sticky = ($stat['mode'] & 01000) !== 0;

            if ($writableByOtherUsers && $sticky === false) {
                throw new StorageOperationFailedException('download_capacity_lock');
            }
        }
    }

    /**
     * Windows PHP does not expose a portable directory-handle-relative open
     * primitive here. Require an explicit operator trust assertion for the
     * entire path/ACL boundary, then reject detectable reparse-point ancestors.
     */
    private function assertTrustedWindowsLockDirectory(string $directory): void
    {
        if ($this->config->get('storix.downloads.windows_lock_directory_trusted', false) !== true) {
            throw new StorageOperationFailedException('download_capacity_lock');
        }

        $absolute = str_replace('/', '\\', $directory);

        if (preg_match('/^[A-Za-z]:\\\\/', $absolute) !== 1) {
            throw new StorageOperationFailedException('download_capacity_lock');
        }

        if (preg_match('/(^|\\\\)\\.\\.(\\\\|$)/', $absolute) === 1) {
            throw new StorageOperationFailedException('download_capacity_lock');
        }

        $normalized = rtrim($absolute, '\\');
        $drive = substr($normalized, 0, 2);
        $segments = array_values(array_filter(
            explode('\\', substr($normalized, 3)),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.',
        ));
        $current = $drive.'\\';

        foreach ($segments as $segment) {
            $current = rtrim($current, '\\').'\\'.$segment;
            clearstatcache(true, $current);
            $linkStat = @lstat($current);
            $targetStat = @stat($current);

            if (
                $linkStat === false
                || $targetStat === false
                || is_link($current)
                || ($linkStat['mode'] & 0170000) !== 0040000
                || ($targetStat['mode'] & 0170000) !== 0040000
                || ! isset($linkStat['ino'], $targetStat['ino'])
                || $linkStat['ino'] === 0
                || $targetStat['ino'] === 0
                || $linkStat['dev'] !== $targetStat['dev']
                || $linkStat['ino'] !== $targetStat['ino']
            ) {
                throw new StorageOperationFailedException('download_capacity_lock');
            }
        }

        $resolved = realpath($directory);

        if ($resolved === false || strcasecmp(rtrim($resolved, '\\'), $normalized) !== 0) {
            throw new StorageOperationFailedException('download_capacity_lock');
        }
    }

    /** @param resource $lockHandle */
    private function lockReleaseCallback(mixed $lockHandle): Closure
    {
        $released = false;

        return static function () use ($lockHandle, &$released): void {
            if ($released) {
                return;
            }

            $released = true;

            if (is_resource($lockHandle)) {
                @flock($lockHandle, LOCK_UN);
                @fclose($lockHandle);
            }
        };
    }

    /** @param resource $source
     * @return array{stream: resource, size: int, sha256: string}
     */
    private function stageAndVerify(mixed $source, int $expectedSize, string $expectedSha256): array
    {
        $staged = null;

        try {
            $staged = $this->stageStream($source, $expectedSize);

            if ($staged['size'] !== $expectedSize || hash_equals(strtolower($expectedSha256), $staged['sha256']) === false) {
                throw new UploadIntegrityException;
            }

            return $staged;
        } catch (Throwable $exception) {
            if (is_array($staged) && is_resource($staged['stream'])) {
                fclose($staged['stream']);
            }

            throw $exception;
        }
    }

    /** @param resource $source
     * @return array{size: int, sha256: string}
     */
    private function verifyRangeStream(mixed $source, int $expectedSize, string $expectedSha256): array
    {
        if (is_resource($source) === false || get_resource_type($source) !== 'stream') {
            throw new StorageOperationFailedException('read');
        }

        $metadata = stream_get_meta_data($source);

        if (($metadata['seekable'] ?? false) === false) {
            $staged = $this->stageStream($source, $expectedSize);

            try {
                if (
                    $staged['size'] !== $expectedSize
                    || hash_equals($expectedSha256, $staged['sha256']) === false
                ) {
                    throw new UploadIntegrityException;
                }

                return ['size' => $staged['size'], 'sha256' => $staged['sha256']];
            } finally {
                if (is_resource($staged['stream'])) {
                    fclose($staged['stream']);
                }
            }
        }

        $hash = hash_init('sha256');
        $size = 0;

        try {
            while (feof($source) === false) {
                $chunk = @fread($source, self::IO_CHUNK_BYTES);

                if ($chunk === false || ($chunk === '' && feof($source) === false)) {
                    throw new StorageOperationFailedException('read');
                }

                if ($chunk === '') {
                    break;
                }

                $size += strlen($chunk);

                if ($size > $expectedSize) {
                    throw new UploadIntegrityException;
                }

                hash_update($hash, $chunk);
            }

            $sha256 = hash_final($hash);

            if ($size !== $expectedSize || hash_equals($expectedSha256, $sha256) === false) {
                throw new UploadIntegrityException;
            }

            return ['size' => $size, 'sha256' => $sha256];
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    /** @param resource $verified
     * @return array{stream: resource, size: int, sha256: string}
     */
    private function copyRange(mixed $verified, int $offset, int $length, string $expectedSha256): array
    {
        $memoryLimit = max(1_048_576, (int) $this->config->get('storix.downloads.spool_memory_bytes', 8 * 1024 * 1024));
        $destination = @fopen('php://temp/maxmemory:'.$memoryLimit, 'w+b');

        if ($destination === false) {
            throw new StorageOperationFailedException('download_stage');
        }

        if (fseek($verified, $offset, SEEK_SET) !== 0) {
            fclose($destination);

            throw new StorageOperationFailedException('download_range');
        }

        $hash = hash_init('sha256');
        $remaining = $length;
        $size = 0;

        try {
            while ($remaining > 0) {
                $chunk = @fread($verified, min(self::IO_CHUNK_BYTES, $remaining));

                if ($chunk === false || $chunk === '') {
                    throw new UploadIntegrityException;
                }

                $this->writeAll($destination, $chunk);
                hash_update($hash, $chunk);
                $chunkSize = strlen($chunk);
                $size += $chunkSize;
                $remaining -= $chunkSize;
            }

            $sha256 = hash_final($hash);

            if ($size !== $length || hash_equals($expectedSha256, $sha256) === false) {
                throw new UploadIntegrityException;
            }

            if (rewind($destination) === false) {
                throw new StorageOperationFailedException('download_stage');
            }

            return ['stream' => $destination, 'size' => $size, 'sha256' => $sha256];
        } catch (Throwable $exception) {
            fclose($destination);

            throw $exception;
        }
    }

    /** @param resource $source
     * @return array{stream: resource, size: int, sha256: string}
     */
    private function stageStream(mixed $source, ?int $maximumBytes = null): array
    {
        if (is_resource($source) === false || get_resource_type($source) !== 'stream') {
            throw new StorageOperationFailedException('read');
        }

        $memoryLimit = max(1_048_576, (int) $this->config->get('storix.downloads.spool_memory_bytes', 8 * 1024 * 1024));
        $destination = @fopen('php://temp/maxmemory:'.$memoryLimit, 'w+b');

        if ($destination === false) {
            fclose($source);

            throw new StorageOperationFailedException('download_stage');
        }

        $hash = hash_init('sha256');
        $size = 0;

        try {
            while (feof($source) === false) {
                $chunk = @fread($source, self::IO_CHUNK_BYTES);

                if ($chunk === false || ($chunk === '' && feof($source) === false)) {
                    throw new StorageOperationFailedException('read');
                }

                if ($chunk === '') {
                    break;
                }

                $size += strlen($chunk);

                if ($maximumBytes !== null && $size > $maximumBytes) {
                    throw new UploadIntegrityException;
                }

                hash_update($hash, $chunk);
                $this->writeAll($destination, $chunk);
            }

            if (rewind($destination) === false) {
                throw new StorageOperationFailedException('download_stage');
            }

            return ['stream' => $destination, 'size' => $size, 'sha256' => hash_final($hash)];
        } catch (Throwable $exception) {
            fclose($destination);

            throw $exception;
        } finally {
            fclose($source);
        }
    }

    /** @param resource $verified
     * @return array{sha256: string}
     */
    private function extractRange(mixed $verified, int $offset, int $length): array
    {
        if (fseek($verified, $offset, SEEK_SET) !== 0) {
            throw new StorageOperationFailedException('download_range');
        }

        $hash = hash_init('sha256');
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = fread($verified, min(self::IO_CHUNK_BYTES, $remaining));

            if ($chunk === false || $chunk === '') {
                throw new UploadIntegrityException;
            }

            hash_update($hash, $chunk);
            $remaining -= strlen($chunk);
        }

        return ['sha256' => hash_final($hash)];
    }

    /** @param resource $destination */
    private function writeAll(mixed $destination, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $written = @fwrite($destination, substr($contents, $offset));

            if ($written === false || $written === 0) {
                throw new StorageOperationFailedException('download_stage');
            }

            $offset += $written;
        }
    }
}
