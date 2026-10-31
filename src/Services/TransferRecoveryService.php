<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use ShamimStack\Storix\Contracts\MultipartStorageDriver;
use ShamimStack\Storix\Contracts\Repositories\TransferRepository;
use ShamimStack\Storix\Drivers\DriverManager;
use ShamimStack\Storix\DTOs\DeleteObjectRequest;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StatObjectRequest;
use ShamimStack\Storix\Enums\StorixErrorCode;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Models\StoredFile;
use ShamimStack\Storix\Models\Transfer;
use Throwable;

final class TransferRecoveryService
{
    public function __construct(
        private readonly DriverManager $drivers,
        private readonly TransferRepository $transfers,
        private readonly TransferStateMachine $states,
        private readonly RetryPolicy $retryPolicy,
    ) {}

    /**
     * Reconcile stale transfers without inventing source bytes or restarting an upload blindly.
     *
     * A completed object can be promoted after full read-back verification. An abandoned
     * multipart session is expired only after its provider workspace and any unpublished
     * object have been cleaned successfully. Other transfers are moved to retry_wait and
     * require the caller to resubmit the original source stream.
     *
     * @return array{examined:int,recovered:int,cleaned:int,expired:int,waiting:int,skipped:int,failed:int}
     */
    public function recover(int $limit = 100, int $staleSeconds = 300, bool $dryRun = false): array
    {
        $now = now();
        $cutoff = $now->copy()->subSeconds(max(30, $staleSeconds));
        $leaseGraceSeconds = max(30, (int) config('storix.transfers.resume_lease_grace_seconds', 60));
        $claimExpiredBefore = $now->copy()->subSeconds($leaseGraceSeconds);
        $limit = max(1, min(1000, $limit));

        $candidates = Transfer::query()
            ->where(function (Builder $query): void {
                $query->whereIn('state', [
                    TransferState::PENDING->value,
                    TransferState::PREPARING->value,
                    TransferState::TRANSFERRING->value,
                    TransferState::PAUSED->value,
                    TransferState::RETRY_WAIT->value,
                    TransferState::VERIFYING->value,
                ])->orWhere(function (Builder $terminal): void {
                    $terminal->whereIn('state', [
                        TransferState::COMPLETED->value,
                        TransferState::FAILED->value,
                        TransferState::CANCELLED->value,
                        TransferState::EXPIRED->value,
                    ])->where(function (Builder $cleanup): void {
                        $cleanup->whereNotNull('multipart_upload_id')
                            ->orWhere('cleanup_pending', true);
                    });
                });
            })
            ->where('updated_at', '<=', $cutoff)
            ->where(function (Builder $query) use ($claimExpiredBefore): void {
                $query->whereNull('resume_lease_expires_at')
                    ->orWhere('resume_lease_expires_at', '<=', $claimExpiredBefore);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', $now);
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $summary = [
            'examined' => $candidates->count(),
            'recovered' => 0,
            'cleaned' => 0,
            'expired' => 0,
            'waiting' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        if ($dryRun) {
            return $summary;
        }

        foreach ($candidates as $candidate) {
            $token = Str::random(64);
            $leaseNow = now();
            $leaseSeconds = max(30, (int) config('storix.transfers.recovery_lease_seconds', 300));

            if ($this->transfers->claimResumeLease(
                (string) $candidate->id,
                $token,
                $leaseNow,
                $leaseNow->copy()->addSeconds($leaseSeconds),
                $leaseNow->copy()->subSeconds($leaseGraceSeconds),
            ) === false) {
                $summary['skipped']++;

                continue;
            }

            try {
                $transfer = Transfer::query()->findOrFail($candidate->id);

                if ($transfer->state->isTerminal()) {
                    $this->cleanupTerminalSession($transfer, $token);
                    $summary['cleaned']++;

                    continue;
                }

                if ($this->recoverPublishedObject($transfer, $token)) {
                    $summary['recovered']++;

                    continue;
                }

                if ($this->isExpired($transfer)) {
                    $this->expireTransfer($transfer, $token);
                    $summary['expired']++;

                    continue;
                }

                if (
                    $transfer->max_attempts > 0
                    && $transfer->attempts >= $transfer->max_attempts
                ) {
                    $this->failExhaustedTransfer($transfer, $token);
                    $summary['failed']++;

                    continue;
                }

                if ($this->retryPolicy->shouldRetryCode(
                    $transfer->last_error_code,
                    max(1, (int) $transfer->attempts),
                    max(1, (int) $transfer->max_attempts),
                ) === false) {
                    $this->failExhaustedTransfer(
                        $transfer,
                        $token,
                        $transfer->last_error_code ?? StorixErrorCode::PERMANENT_FAILURE->value,
                    );
                    $summary['failed']++;

                    continue;
                }

                if ($this->deferForSourceRetry($transfer, $token)) {
                    $summary['waiting']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (Throwable $exception) {
                $retryScheduled = false;

                try {
                    $current = Transfer::query()->find($candidate->id);

                    if (
                        $current !== null
                        && $current->state->isTerminal() === false
                        && $this->ownsRecoveryLease($current, $token)
                    ) {
                        $retryScheduled = $this->recordRecoveryFailure($current, $token, $exception);
                    }
                } catch (Throwable) {
                    Log::error('Storix could not persist the recovery failure state.', [
                        'transfer_id' => $candidate->id,
                        'error_code' => StorixErrorCode::STORAGE_OPERATION_FAILED->value,
                    ]);
                }

                if ($retryScheduled) {
                    $summary['waiting']++;
                } else {
                    $summary['failed']++;
                }

                Log::warning('Storix stale transfer recovery failed.', [
                    'transfer_id' => $candidate->id,
                    'driver' => $candidate->driver,
                    'error_code' => $this->retryPolicy->errorCode($exception),
                    'retry_scheduled' => $retryScheduled,
                ]);
            } finally {
                try {
                    $this->transfers->releaseResumeLease((string) $candidate->id, $token);
                } catch (Throwable $exception) {
                    Log::warning('Storix recovery lease release failed.', [
                        'transfer_id' => $candidate->id,
                        'error_code' => StorixErrorCode::STORAGE_OPERATION_FAILED->value,
                    ]);
                }
            }
        }

        return $summary;
    }

    private function recoverPublishedObject(Transfer $transfer, string $token): bool
    {
        $file = $this->scopedFile($transfer, lock: false);

        if (
            $file === null
            || $file->driver !== $transfer->driver
            || in_array($file->status, ['pending', 'processing', 'failed'], true) === false
            || is_string($file->storage_key) === false
            || $file->storage_key === ''
            || is_string($file->sha256) === false
            || preg_match('/^[a-f0-9]{64}$/iD', $file->sha256) !== 1
        ) {
            return false;
        }

        $driver = $this->drivers->driver($transfer->driver);
        $this->renewRecoveryLease((string) $transfer->id, $token);
        $metadata = $driver->stat(new StatObjectRequest($file->storage_key));
        $this->renewRecoveryLease((string) $transfer->id, $token);

        if ($metadata === null) {
            return false;
        }

        if (
            $metadata->sizeBytes !== (int) $transfer->total_bytes
            || $metadata->sizeBytes !== (int) $file->size_bytes
        ) {
            $this->renewRecoveryLease((string) $transfer->id, $token);
            $driver->delete(new DeleteObjectRequest($file->storage_key));
            $this->renewRecoveryLease((string) $transfer->id, $token);

            return false;
        }

        $this->renewRecoveryLease((string) $transfer->id, $token);
        $stream = $driver->readStream(new ReadStreamRequest($file->storage_key));
        $this->renewRecoveryLease((string) $transfer->id, $token);

        if (is_resource($stream) === false || get_resource_type($stream) !== 'stream') {
            return false;
        }

        try {
            $verified = $this->hashStream(
                $stream,
                fn () => $this->renewRecoveryLease((string) $transfer->id, $token),
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->renewRecoveryLease((string) $transfer->id, $token);

        if (
            $verified['size'] !== (int) $transfer->total_bytes
            || hash_equals(strtolower($file->sha256), $verified['sha256']) === false
        ) {
            $this->renewRecoveryLease((string) $transfer->id, $token);
            $driver->delete(new DeleteObjectRequest($file->storage_key));

            return false;
        }

        $multipartSessionCleaned = $transfer->multipart_upload_id === null;

        if (is_string($transfer->multipart_upload_id) && $transfer->multipart_upload_id !== '') {
            if ($driver instanceof MultipartStorageDriver) {
                try {
                    $this->renewRecoveryLease((string) $transfer->id, $token);
                    $driver->abortMultipartWrite($transfer->multipart_upload_id);
                    $this->renewRecoveryLease((string) $transfer->id, $token);
                    $multipartSessionCleaned = true;
                } catch (Throwable $exception) {
                    $multipartSessionCleaned = false;

                    Log::warning('Storix verified object but could not confirm multipart workspace cleanup.', [
                        'transfer_id' => $transfer->id,
                        'driver' => $transfer->driver,
                        'error_code' => $this->retryPolicy->errorCode($exception),
                    ]);
                }
            } else {
                $multipartSessionCleaned = false;

                Log::warning('Storix verified object but its driver no longer supports multipart cleanup.', [
                    'transfer_id' => $transfer->id,
                    'driver' => $transfer->driver,
                    'error_code' => StorixErrorCode::UNSUPPORTED_CAPABILITY->value,
                ]);
            }
        }

        DB::transaction(function () use ($transfer, $token, $verified, $multipartSessionCleaned): void {
            $current = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if (
                $current->state->isTerminal()
                || $this->ownsRecoveryLease($current, $token) === false
            ) {
                throw new RuntimeException('Transfer recovery ownership changed before finalization.');
            }

            $file = $this->scopedFile($current);

            if ($file === null) {
                throw new RuntimeException('Transfer file is not available in the stored owner scope.');
            }

            $current = $this->transitionToVerifying($current);
            $file->size_bytes = $verified['size'];
            $file->sha256 = $verified['sha256'];
            $file->status = 'available';
            $file->save();

            $current->multipart_upload_id = $multipartSessionCleaned
                ? null
                : $transfer->multipart_upload_id;
            $current->cleanup_pending = false;
            $current->next_retry_at = null;
            $current->transferred_bytes = $current->total_bytes;
            $current->completed_chunks = $current->total_chunks;
            $current->last_error_code = null;
            $current->last_error_message = null;
            $current->last_heartbeat_at = now();
            $this->transfers->save($current);

            $this->states->transition($current->id, TransferState::COMPLETED);
        });

        return true;
    }

    private function cleanupTerminalSession(Transfer $transfer, string $token): void
    {
        $this->cleanupUnpublishedTransfer($transfer, $token);

        DB::transaction(function () use ($transfer, $token): void {
            $current = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($current->state->isTerminal() === false || $this->ownsRecoveryLease($current, $token) === false) {
                return;
            }

            $current->multipart_upload_id = null;
            $current->cleanup_pending = false;
            $current->next_retry_at = null;
            $current->last_heartbeat_at = now();
            $this->transfers->save($current);
        });
    }

    private function expireTransfer(Transfer $transfer, string $token): void
    {
        $this->cleanupUnpublishedTransfer($transfer, $token);

        DB::transaction(function () use ($transfer, $token): void {
            $current = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($current->state->isTerminal() || $this->ownsRecoveryLease($current, $token) === false) {
                return;
            }

            $file = $this->scopedFile($current);

            if ($file !== null && $file->status !== 'available') {
                $file->status = 'failed';
                $file->save();
            }

            $current = $this->states->transition($current->id, TransferState::EXPIRED);
            $current->multipart_upload_id = null;
            $current->cleanup_pending = false;
            $current->next_retry_at = null;
            $current->last_error_code = 'TRANSFER_EXPIRED';
            $current->last_error_message = 'Transfer expired before a verified object could be recovered.';
            $current->last_heartbeat_at = now();
            $this->transfers->save($current);
        });
    }

    private function failExhaustedTransfer(
        Transfer $transfer,
        string $token,
        string $failureCode = 'MAX_RETRIES_EXCEEDED',
    ): void {
        $this->cleanupUnpublishedTransfer($transfer, $token);

        DB::transaction(function () use ($transfer, $token, $failureCode): void {
            $current = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($current->state->isTerminal() || $this->ownsRecoveryLease($current, $token) === false) {
                return;
            }

            $file = $this->scopedFile($current);

            if ($file !== null && $file->status !== 'available') {
                $file->status = 'failed';
                $file->save();
            }

            $current = $this->states->transition($current->id, TransferState::FAILED);
            $current->multipart_upload_id = null;
            $current->cleanup_pending = false;
            $current->next_retry_at = null;
            $current->last_error_code = $failureCode;
            $current->last_error_message = $failureCode === 'MAX_RETRIES_EXCEEDED'
                ? 'The configured transfer attempt limit was reached.'
                : 'A permanent transfer failure prevented automatic recovery.';
            $current->last_heartbeat_at = now();
            $this->transfers->save($current);
        });
    }

    private function recordRecoveryFailure(Transfer $transfer, string $token, Throwable $exception): bool
    {
        $attempt = max(1, (int) $transfer->attempts);
        $maxAttempts = max(1, (int) $transfer->max_attempts);

        if ($this->retryPolicy->shouldRetry($exception, $attempt, $maxAttempts)) {
            return $this->deferForSourceRetry($transfer, $token, $exception);
        }

        $failureCode = $this->retryPolicy->isPermanent($exception)
            ? $this->retryPolicy->errorCode($exception)
            : StorixErrorCode::MAX_RETRIES_EXCEEDED->value;

        $this->failExhaustedTransfer($transfer, $token, $failureCode);

        return false;
    }

    private function deferForSourceRetry(
        Transfer $transfer,
        string $token,
        ?Throwable $failure = null,
    ): bool {
        return DB::transaction(function () use ($transfer, $token, $failure): bool {
            $current = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);

            if ($current->state->isTerminal() || $this->ownsRecoveryLease($current, $token) === false) {
                return false;
            }

            if ($current->state !== TransferState::RETRY_WAIT) {
                if (in_array($current->state, [TransferState::PENDING, TransferState::PAUSED], true)) {
                    $current = $this->states->transition($current->id, TransferState::PREPARING);
                }

                $current = $this->states->transition($current->id, TransferState::RETRY_WAIT);
            }

            if (
                $failure !== null
                || $current->next_retry_at === null
                || $current->next_retry_at->lte(now())
            ) {
                $delay = $this->retryPolicy->delayMilliseconds(
                    max(1, (int) $current->attempts),
                    (int) config('storix.transfers.retry_base_seconds', 2),
                    (int) config('storix.transfers.retry_max_seconds', 300),
                    (int) config('storix.transfers.retry_jitter_percent', 20),
                );

                $current->next_retry_at = now()->addMilliseconds($delay);
            }

            if ($failure !== null) {
                $current->last_error_code = $this->retryPolicy->errorCode($failure);
                $current->last_error_message = 'Recovery failed temporarily; retry requires the original source stream.';
            } else {
                $current->last_error_code ??= StorixErrorCode::STORAGE_OPERATION_FAILED->value;
                $current->last_error_message = 'Recovery found no verified completed object; retry requires the original source stream.';
            }
            $current->last_heartbeat_at = now();
            $this->transfers->save($current);

            return true;
        });
    }

    private function cleanupUnpublishedTransfer(Transfer $transfer, string $token): void
    {
        $driver = $this->drivers->driver($transfer->driver);

        if (is_string($transfer->multipart_upload_id) && $transfer->multipart_upload_id !== '') {
            if ($driver instanceof MultipartStorageDriver === false) {
                throw new UnsupportedCapabilityException('multipart_writes', $driver->name());
            }

            $this->renewRecoveryLease((string) $transfer->id, $token);
            $driver->abortMultipartWrite($transfer->multipart_upload_id);
            $this->renewRecoveryLease((string) $transfer->id, $token);
        }

        $file = $this->scopedFile($transfer, lock: false);

        if ($file !== null && $file->storage_key !== '' && $file->status !== 'available') {
            $this->renewRecoveryLease((string) $transfer->id, $token);
            $exists = $driver->stat(new StatObjectRequest($file->storage_key)) !== null;
            $this->renewRecoveryLease((string) $transfer->id, $token);

            if ($exists) {
                $driver->delete(new DeleteObjectRequest($file->storage_key));
                $this->renewRecoveryLease((string) $transfer->id, $token);
            }
        }
    }

    private function isExpired(Transfer $transfer): bool
    {
        if ($transfer->expires_at !== null && $transfer->expires_at->isPast()) {
            return true;
        }

        $startedAt = $transfer->multipart_started_at ?? $transfer->started_at ?? $transfer->created_at;
        $ttlHours = max(1, (int) config('storix.transfers.session_ttl_hours', 24));

        return $startedAt !== null && $startedAt->lte(now()->subHours($ttlHours));
    }

    private function transitionToVerifying(Transfer $transfer): Transfer
    {
        if ($transfer->state === TransferState::VERIFYING) {
            return $transfer;
        }

        if (in_array($transfer->state, [TransferState::PENDING, TransferState::PAUSED, TransferState::RETRY_WAIT], true)) {
            $transfer = $this->states->transition($transfer->id, TransferState::PREPARING);
        }

        if ($transfer->state === TransferState::PREPARING) {
            $transfer = $this->states->transition($transfer->id, TransferState::TRANSFERRING);
        }

        return $this->states->transition($transfer->id, TransferState::VERIFYING);
    }

    private function scopedFile(Transfer $transfer, bool $lock = true): ?StoredFile
    {
        $query = StoredFile::query()->whereKey($transfer->file_id);

        $transfer->tenant_id === null
            ? $query->whereNull('tenant_id')
            : $query->where('tenant_id', $transfer->tenant_id);

        $transfer->owner_id === null
            ? $query->whereNull('owner_id')
            : $query->where('owner_id', $transfer->owner_id);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function ownsRecoveryLease(Transfer $transfer, string $token): bool
    {
        return $transfer->resume_lease_token === $token
            && $transfer->resume_lease_expires_at !== null
            && $transfer->resume_lease_expires_at->isFuture();
    }

    private function renewRecoveryLease(string $transferId, string $token): void
    {
        $now = now();
        $leaseSeconds = max(30, (int) config('storix.transfers.recovery_lease_seconds', 300));

        if ($this->transfers->renewResumeLease(
            $transferId,
            $token,
            $now,
            $now->copy()->addSeconds($leaseSeconds),
        ) === false) {
            throw new RuntimeException('Transfer recovery lease was lost.');
        }
    }

    /**
     * @param  resource  $stream
     * @return array{size:int,sha256:string}
     */
    private function hashStream(mixed $stream, ?callable $heartbeat = null): array
    {
        $hash = hash_init('sha256');
        $size = 0;
        $lastHeartbeatAt = time();
        $heartbeatInterval = max(1, (int) config('storix.transfers.heartbeat_interval_seconds', 15));

        while (feof($stream) === false) {
            $buffer = fread($stream, 1_048_576);

            if ($buffer === false) {
                throw new RuntimeException('Unable to read the recovered storage object.');
            }

            if ($buffer === '') {
                if (feof($stream)) {
                    break;
                }

                throw new RuntimeException('Recovered storage stream stopped before EOF.');
            }

            hash_update($hash, $buffer);
            $size += strlen($buffer);

            if ($heartbeat !== null && time() - $lastHeartbeatAt >= $heartbeatInterval) {
                $heartbeat();
                $lastHeartbeatAt = time();
            }
        }

        return ['size' => $size, 'sha256' => hash_final($hash)];
    }
}
