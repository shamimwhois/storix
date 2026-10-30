<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Uploads;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use ShamimStack\Storix\Contracts\Repositories\DirectoryRepository;
use ShamimStack\Storix\Contracts\Repositories\StoredFileRepository;
use ShamimStack\Storix\Contracts\Repositories\TransferRepository;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\DTOs\DeleteObjectRequest;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StatObjectRequest;
use ShamimStack\Storix\DTOs\UploadRequest;
use ShamimStack\Storix\DTOs\UploadResult;
use ShamimStack\Storix\DTOs\WriteStreamRequest;
use ShamimStack\Storix\Enums\ObjectVisibility;
use ShamimStack\Storix\Enums\StorixErrorCode;
use ShamimStack\Storix\Enums\TransferDirection;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Exceptions\IdempotencyConflictException;
use ShamimStack\Storix\Exceptions\StorageOperationFailedException;
use ShamimStack\Storix\Exceptions\StorixException;
use ShamimStack\Storix\Exceptions\UnsupportedCapabilityException;
use ShamimStack\Storix\Exceptions\UploadInProgressException;
use ShamimStack\Storix\Exceptions\UploadIntegrityException;
use ShamimStack\Storix\Exceptions\UploadTooLargeException;
use ShamimStack\Storix\Exceptions\UploadValidationException;
use ShamimStack\Storix\Models\Directory;
use ShamimStack\Storix\Models\StoredFile;
use ShamimStack\Storix\Models\Transfer;
use ShamimStack\Storix\Services\TransferStateMachine;
use Throwable;

final class UploadManager
{
    private const int STAGING_CHUNK_BYTES = 1_048_576;

    public function __construct(
        private readonly DriverManager $drivers,
        private readonly StoredFileRepository $files,
        private readonly TransferRepository $transfers,
        private readonly DirectoryRepository $directories,
        private readonly TransferStateMachine $states,
        private readonly ConfigRepository $config,
    ) {}

    public function upload(UploadRequest $request): UploadResult
    {
        $driver = $this->drivers->driver($request->driver);
        $driverName = $driver->name();

        $capabilities = $driver->capabilities();

        if ($capabilities->supportsStreaming === false) {
            throw new UnsupportedCapabilityException('streaming', $driverName);
        }

        if ($request->visibility !== ObjectVisibility::PRIVATE) {
            throw new UploadValidationException('Only private uploads are supported by E05.');
        }

        $staged = $this->stage($request, $capabilities->maxObjectSizeBytes);
        $transfer = null;
        $file = null;
        $storageKey = null;
        $ownsTransfer = false;
        $objectMayExist = false;

        try {
            $directory = $this->resolveDirectory($request, $driverName);
            $fingerprint = $this->requestFingerprint($request, $driverName, $directory, $staged);
            $extension = $this->extension($request->originalName);
            $storageKey = $this->storageKey($directory, $extension);

            $transfer = $this->transfers->firstOrCreateIdempotent([
                'file_id' => null,
                'tenant_id' => $request->tenantId,
                'owner_id' => $request->ownerId,
                'direction' => TransferDirection::UPLOAD,
                'driver' => $driverName,
                'state' => TransferState::PENDING,
                'source_name' => $request->originalName,
                'total_bytes' => $staged['size'],
                'transferred_bytes' => 0,
                'total_chunks' => 0,
                'completed_chunks' => 0,
                'fingerprint' => $fingerprint,
                'idempotency_key' => $request->idempotencyKey,
                'attempts' => 0,
                'max_attempts' => 1,
            ]);

            if ($transfer->wasRecentlyCreated === false) {
                return $this->resolveExisting($transfer, $fingerprint);
            }

            $ownsTransfer = true;

            [$file, $transfer] = DB::transaction(function () use (
                $request,
                $directory,
                $driverName,
                $storageKey,
                $extension,
                $staged,
                $transfer,
            ): array {
                $file = $this->files->create([
                    'tenant_id' => $request->tenantId,
                    'owner_id' => $request->ownerId,
                    'directory_id' => $directory?->id,
                    'driver' => $driverName,
                    'name' => $request->originalName,
                    'original_name' => $request->originalName,
                    'extension' => $extension,
                    'mime_type' => $request->mimeType,
                    'size_bytes' => $staged['size'],
                    'sha256' => $staged['sha256'],
                    'storage_key' => $storageKey,
                    'status' => 'pending',
                    'visibility' => $request->visibility,
                    'metadata' => $request->metadata,
                ]);

                $transfer->file_id = $file->id;
                $transfer = $this->transfers->save($transfer);

                return [$file, $transfer];
            });

            $transfer = $this->states->transition($transfer->id, TransferState::PREPARING);
            $file->status = 'processing';
            $file = $this->files->save($file);
            $transfer->attempts = 1;
            $transfer = $this->transfers->save($transfer);
            $transfer = $this->states->transition($transfer->id, TransferState::TRANSFERRING);

            $objectMayExist = true;
            $writeResult = $driver->writeStream(new WriteStreamRequest(
                key: $storageKey,
                stream: $staged['stream'],
                mimeType: $request->mimeType,
                visibility: $request->visibility,
                metadata: $request->metadata,
            ));

            if ($writeResult->object->key !== $storageKey || $writeResult->object->sizeBytes !== $staged['size']) {
                throw new UploadIntegrityException;
            }

            if (
                $writeResult->object->checksumSha256 !== null
                && strtolower($writeResult->object->checksumSha256) !== $staged['sha256']
            ) {
                throw new UploadIntegrityException;
            }

            $transfer->transferred_bytes = $staged['size'];
            $transfer = $this->transfers->save($transfer);
            $transfer = $this->states->transition($transfer->id, TransferState::VERIFYING);

            $storedMetadata = $driver->stat(new StatObjectRequest($storageKey));

            if ($storedMetadata === null || $storedMetadata->sizeBytes !== $staged['size']) {
                throw new UploadIntegrityException;
            }

            $readBack = $driver->readStream(new ReadStreamRequest($storageKey));

            if (is_resource($readBack) === false || get_resource_type($readBack) !== 'stream') {
                throw new StorageOperationFailedException('verify_read');
            }

            try {
                $verified = $this->hashStream($readBack);
            } finally {
                if (is_resource($readBack)) {
                    fclose($readBack);
                }
            }

            if ($verified['size'] !== $staged['size'] || $verified['sha256'] !== $staged['sha256']) {
                throw new UploadIntegrityException;
            }

            [$file, $transfer] = DB::transaction(function () use (
                $file,
                $transfer,
                $writeResult,
                $staged,
            ): array {
                $file->size_bytes = $staged['size'];
                $file->sha256 = $staged['sha256'];
                $file->mime_type = $writeResult->object->mimeType ?? $file->mime_type;
                $file->status = 'available';
                $file = $this->files->save($file);

                $transfer = $this->states->transition($transfer->id, TransferState::COMPLETED);

                return [$file, $transfer];
            });

            return new UploadResult($file, $transfer);
        } catch (Throwable $exception) {
            if ($ownsTransfer) {
                $cleanupFailed = false;

                if ($objectMayExist && $storageKey !== null) {
                    try {
                        $driver->delete(new DeleteObjectRequest($storageKey));
                    } catch (Throwable $cleanupException) {
                        $cleanupFailed = true;
                        Log::warning('Storix upload cleanup failed.', [
                            'transfer_id' => $transfer?->id,
                            'driver' => $driverName,
                            'error_code' => $cleanupException instanceof StorixException
                                ? $cleanupException->errorCode->value
                                : StorixErrorCode::STORAGE_OPERATION_FAILED->value,
                        ]);
                    }
                }

                $this->markFailed($transfer, $file, $request, $exception, $cleanupFailed);
            }

            throw $exception;
        } finally {
            if (is_resource($staged['stream'])) {
                fclose($staged['stream']);
            }
        }
    }

    /**
     * @return array{stream: resource, size: int, sha256: string}
     */
    private function stage(UploadRequest $request, ?int $driverMaximumBytes): array
    {
        $configuredMaximumBytes = (int) $this->config->get('storix.uploads.max_file_size_bytes', 67_108_864);
        $maximumBytes = $driverMaximumBytes === null
            ? $configuredMaximumBytes
            : min($configuredMaximumBytes, $driverMaximumBytes);
        $memoryBytes = (int) $this->config->get('storix.uploads.spool_memory_bytes', 8_388_608);

        if ($maximumBytes < 1 || $memoryBytes < 1) {
            throw new RuntimeException('Storix upload size and spool settings must be positive integers.');
        }

        if ($request->expectedSizeBytes !== null && $request->expectedSizeBytes > $maximumBytes) {
            throw new UploadTooLargeException($maximumBytes);
        }

        $staging = @fopen('php://temp/maxmemory:'.$memoryBytes, 'w+b');

        if ($staging === false) {
            throw new StorageOperationFailedException('stage');
        }

        $hash = hash_init('sha256');
        $size = 0;

        try {
            while (feof($request->stream) === false) {
                $buffer = @fread($request->stream, self::STAGING_CHUNK_BYTES);

                if ($buffer === false) {
                    throw new StorageOperationFailedException('stage');
                }

                if ($buffer === '') {
                    if (feof($request->stream)) {
                        break;
                    }

                    throw new StorageOperationFailedException('stage');
                }

                $length = strlen($buffer);

                if ($size > $maximumBytes - $length) {
                    throw new UploadTooLargeException($maximumBytes);
                }

                $this->writeAll($staging, $buffer);
                hash_update($hash, $buffer);
                $size += $length;
            }

            if ($request->expectedSizeBytes !== null && $request->expectedSizeBytes !== $size) {
                throw new UploadValidationException('Actual upload size does not match expectedSizeBytes.');
            }

            if (@rewind($staging) === false) {
                throw new StorageOperationFailedException('stage');
            }

            return [
                'stream' => $staging,
                'size' => $size,
                'sha256' => hash_final($hash),
            ];
        } catch (Throwable $exception) {
            if (is_resource($staging)) {
                fclose($staging);
            }

            throw $exception;
        }
    }

    /**
     * @param  resource  $stream
     */
    private function writeAll(mixed $stream, string $buffer): void
    {
        $length = strlen($buffer);
        $written = 0;

        while ($written < $length) {
            $result = @fwrite($stream, substr($buffer, $written));

            if ($result === false || $result === 0) {
                throw new StorageOperationFailedException('stage');
            }

            $written += $result;
        }
    }

    /**
     * @param  resource  $stream
     * @return array{size: int, sha256: string}
     */
    private function hashStream(mixed $stream): array
    {
        $hash = hash_init('sha256');
        $size = 0;

        while (feof($stream) === false) {
            $buffer = @fread($stream, self::STAGING_CHUNK_BYTES);

            if ($buffer === false) {
                throw new StorageOperationFailedException('verify_read');
            }

            if ($buffer === '') {
                if (feof($stream)) {
                    break;
                }

                throw new StorageOperationFailedException('verify_read');
            }

            hash_update($hash, $buffer);
            $size += strlen($buffer);
        }

        return ['size' => $size, 'sha256' => hash_final($hash)];
    }

    private function resolveDirectory(UploadRequest $request, string $driverName): ?Directory
    {
        if ($request->directoryId === null) {
            return null;
        }

        $directory = $this->directories->findScoped(
            $request->directoryId,
            $request->tenantId,
            $request->ownerId,
        );

        if ($directory === null || $directory->driver !== $driverName) {
            throw new UploadValidationException('The selected directory is not available in this upload scope.');
        }

        return $directory;
    }

    /**
     * @param  array{stream: resource, size: int, sha256: string}  $staged
     */
    private function requestFingerprint(
        UploadRequest $request,
        string $driverName,
        ?Directory $directory,
        array $staged,
    ): string {
        $metadata = $request->metadata;
        ksort($metadata);

        return hash('sha256', json_encode([
            'sha256' => $staged['sha256'],
            'size_bytes' => $staged['size'],
            'original_name' => $request->originalName,
            'driver' => $driverName,
            'directory_id' => $directory?->id,
            'mime_type' => $request->mimeType,
            'visibility' => $request->visibility->value,
            'metadata' => $metadata,
        ], JSON_THROW_ON_ERROR));
    }

    private function extension(string $originalName): ?string
    {
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);

        if ($extension === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $extension) !== 1) {
            return null;
        }

        return strtolower($extension);
    }

    private function storageKey(?Directory $directory, ?string $extension): string
    {
        $prefix = $directory === null ? 'uploads' : trim($directory->path_key, '/');
        $identifier = Str::uuid()->toString().($extension === null ? '' : '.'.$extension);

        return $prefix === '' ? $identifier : $prefix.'/'.$identifier;
    }

    private function resolveExisting(Transfer $transfer, string $fingerprint): UploadResult
    {
        if ($transfer->fingerprint !== $fingerprint) {
            throw new IdempotencyConflictException;
        }

        if ($transfer->state === TransferState::COMPLETED) {
            $file = $transfer->file()->first();

            if ($file !== null && $file->status === 'available') {
                return new UploadResult($file, $transfer, true);
            }

            throw new IdempotencyConflictException;
        }

        if ($transfer->state->isTerminal() === false) {
            throw new UploadInProgressException;
        }

        throw new IdempotencyConflictException;
    }

    private function markFailed(
        ?Transfer $transfer,
        ?StoredFile $file,
        UploadRequest $request,
        Throwable $exception,
        bool $cleanupFailed,
    ): void {
        if ($transfer === null) {
            return;
        }

        try {
            DB::transaction(function () use ($transfer, $file, $request, $exception, $cleanupFailed): void {
                if ($file !== null) {
                    $file->status = 'failed';
                    $this->files->save($file);
                }

                $current = $this->transfers->findScoped(
                    $transfer->id,
                    $request->tenantId,
                    $request->ownerId,
                );

                if ($current === null || $current->state->isTerminal()) {
                    return;
                }

                $current = $this->states->transition($current->id, TransferState::FAILED);
                $current->last_error_code = $exception instanceof StorixException
                    ? $exception->errorCode->value
                    : StorixErrorCode::STORAGE_OPERATION_FAILED->value;
                $current->last_error_message = $cleanupFailed
                    ? 'Upload failed and storage cleanup also failed.'
                    : 'Upload failed during storage or integrity verification.';
                $this->transfers->save($current);
            });
        } catch (Throwable $persistenceException) {
            Log::error('Storix could not persist failed upload state.', [
                'transfer_id' => $transfer->id,
                'error_code' => $persistenceException instanceof StorixException
                    ? $persistenceException->errorCode->value
                    : StorixErrorCode::STORAGE_OPERATION_FAILED->value,
            ]);
        }
    }
}
