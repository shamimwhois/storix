<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Downloads;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ShamimStack\Storix\Contracts\Repositories\StoredFileRepository;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\DTOs\DownloadRequest;
use ShamimStack\Storix\DTOs\DownloadResult;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StatObjectRequest;
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

        if ($request->isRangeRequest() && (int) $request->offset >= $metadata->sizeBytes) {
            throw new StorageOperationFailedException('download_range');
        }

        $verified = $this->stageAndVerify(
            $driver->readStream(new ReadStreamRequest((string) $file->storage_key)),
            (int) $file->size_bytes,
            (string) $file->sha256,
        );

        if ($request->isRangeRequest() === false) {
            rewind($verified['stream']);

            return new DownloadResult($file, $verified['stream'], $verified['size'], 0, false, true);
        }

        $offset = (int) $request->offset;

        $length = min((int) $request->length, $verified['size'] - $offset);
        $range = null;

        try {
            $range = $this->stageStream($driver->readStream(new ReadStreamRequest(
                key: (string) $file->storage_key,
                offset: $offset,
                length: $length,
            )), $length);
            $expected = $this->extractRange($verified['stream'], $offset, $length);

            if ($range['size'] !== $length || hash_equals($expected['sha256'], $range['sha256']) === false) {
                throw new UploadIntegrityException;
            }

            fclose($verified['stream']);
            rewind($range['stream']);

            return new DownloadResult($file, $range['stream'], $length, $offset, true, true);
        } catch (Throwable $exception) {
            fclose($verified['stream']);

            if (is_array($range) && is_resource($range['stream'])) {
                fclose($range['stream']);
            }

            throw $exception;
        }
    }

    /** @param resource $source
     *  @return array{stream: resource, size: int, sha256: string}
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
     *  @return array{stream: resource, size: int, sha256: string}
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
     *  @return array{sha256: string}
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
