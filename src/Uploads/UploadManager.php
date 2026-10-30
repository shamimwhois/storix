<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Uploads;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use ShamimStack\Storix\Contracts\MultipartStorageDriver;
use ShamimStack\Storix\Contracts\Repositories\ChunkRepository;
use ShamimStack\Storix\Contracts\Repositories\DirectoryRepository;
use ShamimStack\Storix\Contracts\Repositories\StoredFileRepository;
use ShamimStack\Storix\Contracts\Repositories\TransferRepository;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\DTOs\BeginMultipartWriteRequest;
use ShamimStack\Storix\DTOs\ChunkDescriptor;
use ShamimStack\Storix\DTOs\CompleteMultipartWriteRequest;
use ShamimStack\Storix\DTOs\DeleteObjectRequest;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StatObjectRequest;
use ShamimStack\Storix\DTOs\UploadRequest;
use ShamimStack\Storix\DTOs\UploadResult;
use ShamimStack\Storix\DTOs\WriteMultipartPartRequest;
use ShamimStack\Storix\DTOs\WriteResult;
use ShamimStack\Storix\DTOs\WriteStreamRequest;
use ShamimStack\Storix\Enums\ChunkState;
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
use ShamimStack\Storix\Services\ChunkPlanner;
use ShamimStack\Storix\Services\MultipartSessionReconciler;
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
        private readonly ChunkRepository $chunks,
        private readonly ChunkPlanner $chunkPlanner,
        private readonly MultipartSessionReconciler $multipartSessions,
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

        $multipartDriver = null;

        if ($capabilities->supportsMultipartWrites) {
            if ($driver instanceof MultipartStorageDriver === false) {
                throw new UnsupportedCapabilityException('multipart_writes', $driverName);
            }

            $multipartDriver = $driver;
        }

        if ($request->visibility !== ObjectVisibility::PRIVATE) {
            throw new UploadValidationException('Only private uploads are supported by E05.');
        }

        $staged = $this->stage($request, $capabilities->maxObjectSizeBytes);
        $chunkPlan = [];
        $transfer = null;
        $file = null;
        $storageKey = null;
        $ownsTransfer = false;
        $objectMayExist = false;
        $resumeLeaseToken = null;
        $isResume = false;
        $preserveSessionOnFailure = false;

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
                if ($transfer->fingerprint !== $fingerprint) {
                    throw new IdempotencyConflictException;
                }

                if ($transfer->state === TransferState::COMPLETED) {
                    return $this->resolveExisting($transfer, $fingerprint);
                }

                if (
                    $multipartDriver === null
                    || $transfer->state->isTerminal()
                    || ! in_array($transfer->state, [
                        TransferState::PREPARING,
                        TransferState::TRANSFERRING,
                        TransferState::PAUSED,
                        TransferState::RETRY_WAIT,
                    ], true)
                    || ! is_string($transfer->multipart_upload_id)
                    || $transfer->multipart_upload_id === ''
                    || $transfer->file_id === null
                ) {
                    throw new UploadInProgressException;
                }

                $file = $this->files->findScoped(
                    (string) $transfer->file_id,
                    $request->tenantId,
                    $request->ownerId,
                );

                if (
                    $file === null
                    || $file->driver !== $driverName
                    || ! in_array($file->status, ['pending', 'processing'], true)
                    || ! is_string($file->storage_key)
                    || $file->storage_key === ''
                ) {
                    throw new IdempotencyConflictException;
                }

                $storageKey = $file->storage_key;
                $isResume = true;
                $preserveSessionOnFailure = true;
            }

            $resumeLeaseToken = Str::random(64);
            $leaseSeconds = max(30, (int) $this->config->get('storix.transfers.resume_lease_seconds', 300));

            if ($this->transfers->claimResumeLease(
                (string) $transfer->id,
                $resumeLeaseToken,
                now(),
                now()->addSeconds($leaseSeconds),
            ) === false) {
                throw new UploadInProgressException;
            }

            $ownsTransfer = true;
            $renewLease = fn (): void => $this->renewResumeLease($transfer, $resumeLeaseToken, $leaseSeconds);

            if ($isResume) {
                $persistedChunks = $this->chunks->forTransfer((string) $transfer->id);
                $chunkPlan = $this->chunkPlanFromPersisted($persistedChunks, $staged['size']);

                if (count($chunkPlan) !== $transfer->total_chunks) {
                    throw new UploadIntegrityException;
                }
            } elseif ($multipartDriver !== null && $staged['size'] > 0) {
                $chunkPlan = $this->chunkPlanner->plan(
                    $staged['size'],
                    $capabilities,
                    (int) $this->config->get('storix.transfers.chunk_size', $capabilities->preferredChunkSizeBytes),
                );
            }

            if (! $isResume) {
                $transfer->total_chunks = count($chunkPlan);
                $transfer = $this->transfers->save($transfer);

                [$file, $transfer] = DB::transaction(function () use (
                    $request,
                    $directory,
                    $driverName,
                    $storageKey,
                    $extension,
                    $staged,
                    $transfer,
                    $chunkPlan,
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

                    foreach ($chunkPlan as $descriptor) {
                        $this->chunks->create([
                            'transfer_id' => $transfer->id,
                            'chunk_index' => $descriptor->index,
                            'offset_bytes' => $descriptor->offsetBytes,
                            'size_bytes' => $descriptor->sizeBytes,
                            'sha256' => null,
                            'state' => ChunkState::PENDING,
                            'driver_reference' => null,
                            'attempts' => 0,
                            'last_error_code' => null,
                        ]);
                    }

                    return [$file, $transfer];
                });
            } else {
                $this->states->transition($transfer->id, TransferState::PAUSED);
            }

            $transfer = $this->states->transition($transfer->id, TransferState::PREPARING);
            $file->status = 'processing';
            $file = $this->files->save($file);
            $transfer->attempts++;
            $transfer = $this->transfers->save($transfer);
            $transfer = $this->states->transition($transfer->id, TransferState::TRANSFERRING);

            $objectMayExist = true;

            if ($multipartDriver !== null && $chunkPlan !== []) {
                $writeResult = $this->writeMultipart(
                    $multipartDriver,
                    $request,
                    $transfer,
                    $storageKey,
                    $staged,
                    $chunkPlan,
                    $renewLease,
                );
            } else {
                $renewLease();
                $writeResult = $driver->writeStream(new WriteStreamRequest(
                    key: $storageKey,
                    stream: $staged['stream'],
                    mimeType: $request->mimeType,
                    visibility: $request->visibility,
                    metadata: $request->metadata,
                ));
                $renewLease();
            }

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
            $transfer->completed_chunks = count($chunkPlan);
            $transfer = $this->transfers->save($transfer);
            $renewLease();
            $transfer = $this->states->transition($transfer->id, TransferState::VERIFYING);

            $storedMetadata = $driver->stat(new StatObjectRequest($storageKey));

            if ($storedMetadata === null || $storedMetadata->sizeBytes !== $staged['size']) {
                throw new UploadIntegrityException;
            }

            $renewLease();
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

            $renewLease();

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
                $leaseStillOwned = false;

                try {
                    $this->renewResumeLease($transfer, $resumeLeaseToken, $leaseSeconds);
                    $leaseStillOwned = true;
                } catch (Throwable $leaseException) {
                    Log::warning('Storix upload ownership could not be confirmed after failure.', [
                        'transfer_id' => $transfer?->id,
                        'error_code' => $leaseException instanceof StorixException
                            ? $leaseException->errorCode->value
                            : StorixErrorCode::STORAGE_OPERATION_FAILED->value,
                    ]);
                }

                if ($leaseStillOwned === false) {
                    throw $exception;
                }

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
            if ($resumeLeaseToken !== null && $transfer !== null) {
                try {
                    $this->transfers->releaseResumeLease((string) $transfer->id, $resumeLeaseToken);
                } catch (Throwable $leaseException) {
                    Log::warning('Storix resume lease release failed.', [
                        'transfer_id' => $transfer->id,
                        'error_code' => $leaseException instanceof StorixException
                            ? $leaseException->errorCode->value
                            : StorixErrorCode::STORAGE_OPERATION_FAILED->value,
                    ]);
                }
            }

            if (is_resource($staged['stream'])) {
                fclose($staged['stream']);
            }
        }
    }

    private function renewResumeLease(Transfer $transfer, string $token, int $leaseSeconds): void
    {
        $now = now();

        if ($this->transfers->renewResumeLease(
            (string) $transfer->id,
            $token,
            $now,
            $now->copy()->addSeconds($leaseSeconds),
        ) === false) {
            throw new UploadInProgressException;
        }
    }

    /**
     * @param  array{stream: resource, size: int, sha256: string}  $staged
     * @param  list<ChunkDescriptor>  $plan
     */
    private function writeMultipart(
        MultipartStorageDriver $driver,
        UploadRequest $request,
        Transfer $transfer,
        string $storageKey,
        array $staged,
        array $plan,
        callable $renewLease,
    ): WriteResult {
        $uploadId = null;

        try {
            $renewLease();
            $uploadId = $driver->beginMultipartWrite(new BeginMultipartWriteRequest(
                key: $storageKey,
                mimeType: $request->mimeType,
                visibility: $request->visibility,
                metadata: $request->metadata,
            ));

            $transfer->multipart_upload_id = $uploadId;
            $transfer->multipart_started_at = now();
            $transfer = $this->transfers->save($transfer);

            $parts = [];

            foreach ($plan as $descriptor) {
                $renewLease();
                $chunk = $this->chunks->findByIndex((string) $transfer->id, $descriptor->index);

                if ($chunk === null) {
                    throw new StorageOperationFailedException('chunk_lookup');
                }

                $chunk->state = ChunkState::UPLOADING;
                $chunk->attempts++;
                $chunk = $this->chunks->save($chunk);

                $partStream = null;

                try {
                    $stagedPart = $this->stageChunk($staged['stream'], $descriptor);
                    $partStream = $stagedPart['stream'];

                    $part = $driver->writeMultipartPart(new WriteMultipartPartRequest(
                        uploadId: $uploadId,
                        partNumber: $descriptor->index + 1,
                        stream: $partStream,
                    ));
                    $renewLease();

                    if (
                        $part->partNumber !== $descriptor->index + 1
                        || $part->sizeBytes !== $descriptor->sizeBytes
                        || hash_equals($stagedPart['sha256'], strtolower($part->sha256)) === false
                    ) {
                        throw new UploadIntegrityException;
                    }

                    $chunk->sha256 = strtolower($part->sha256);
                    $chunk->driver_reference = $part->driverReference;
                    $chunk->state = ChunkState::VERIFIED;
                    $chunk->verified_at = now();
                    $this->chunks->save($chunk);

                    $parts[] = $part;
                    $transfer->transferred_bytes += $part->sizeBytes;
                    $transfer->completed_chunks++;
                    $this->transfers->save($transfer);
                } finally {
                    if (is_resource($partStream)) {
                        fclose($partStream);
                    }
                }
            }

            $renewLease();
            $result = $driver->completeMultipartWrite(new CompleteMultipartWriteRequest(
                uploadId: $uploadId,
                key: $storageKey,
                parts: $parts,
                expectedSizeBytes: $staged['size'],
                expectedSha256: $staged['sha256'],
                mimeType: $request->mimeType,
                visibility: $request->visibility,
                metadata: $request->metadata,
            ));

            $transfer->multipart_upload_id = null;
            $transfer = $this->transfers->save($transfer);

            return $result;
        } catch (Throwable $exception) {
            try {
                $errorCode = $exception instanceof StorixException
                    ? $exception->errorCode->value
                    : StorixErrorCode::STORAGE_OPERATION_FAILED->value;

                try {
                    foreach ($this->chunks->forTransfer((string) $transfer->id) as $chunk) {
                        if ($chunk->state !== ChunkState::VERIFIED) {
                            $chunk->state = ChunkState::FAILED;
                            $chunk->last_error_code = $errorCode;
                            $this->chunks->save($chunk);
                        }
                    }
                } catch (Throwable $persistenceException) {
                    Log::warning('Storix multipart failure state could not be persisted.', [
                        'transfer_id' => $transfer->id,
                        'driver' => $driver->name(),
                        'error_code' => $persistenceException instanceof StorixException
                            ? $persistenceException->errorCode->value
                            : StorixErrorCode::STORAGE_OPERATION_FAILED->value,
                    ]);
                }
            } finally {
                if ($uploadId !== null) {
                    try {
                        $driver->abortMultipartWrite($uploadId);
                        $transfer->multipart_upload_id = null;
                        $this->transfers->save($transfer);
                    } catch (Throwable $abortException) {
                        Log::warning('Storix multipart abort or session cleanup failed.', [
                            'transfer_id' => $transfer->id,
                            'driver' => $driver->name(),
                            'error_code' => $abortException instanceof StorixException
                                ? $abortException->errorCode->value
                                : StorixErrorCode::STORAGE_OPERATION_FAILED->value,
                        ]);
                    }
                }
            }

            throw $exception;
        }
    }

    /**
     * @return array{stream: resource, sha256: string}
     */
    private function stageChunk(mixed $source, ChunkDescriptor $descriptor): array
    {
        if (@fseek($source, $descriptor->offsetBytes, SEEK_SET) !== 0) {
            throw new StorageOperationFailedException('chunk_stage');
        }

        $memoryBytes = (int) $this->config->get('storix.uploads.spool_memory_bytes', 8_388_608);

        if ($memoryBytes < 1) {
            throw new RuntimeException('Storix spool memory limit must be a positive integer.');
        }

        $stream = @fopen('php://temp/maxmemory:'.$memoryBytes, 'w+b');

        if ($stream === false) {
            throw new StorageOperationFailedException('chunk_stage');
        }

        $hash = hash_init('sha256');
        $remaining = $descriptor->sizeBytes;

        try {
            while ($remaining > 0) {
                $buffer = @fread($source, min(self::STAGING_CHUNK_BYTES, $remaining));

                if ($buffer === false || $buffer === '') {
                    throw new StorageOperationFailedException('chunk_stage');
                }

                $this->writeAll($stream, $buffer);
                hash_update($hash, $buffer);
                $remaining -= strlen($buffer);
            }

            if (@rewind($stream) === false) {
                throw new StorageOperationFailedException('chunk_stage');
            }

            return [
                'stream' => $stream,
                'sha256' => hash_final($hash),
            ];
        } catch (Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw $exception;
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
            'tenant_id' => $request->tenantId,
            'owner_id' => $request->ownerId,
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
