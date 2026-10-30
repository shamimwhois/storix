<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Repositories\Eloquent;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use ShamimStack\Storix\Contracts\Repositories\TransferRepository;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Models\Transfer;

final class EloquentTransferRepository implements TransferRepository
{
    public function create(array $attributes): Transfer
    {
        return Transfer::query()->create($this->normalizeIdempotencyAttributes($attributes));
    }

    public function findScoped(string $id, ?string $tenantId, ?string $ownerId): ?Transfer
    {
        return $this->applyScope(Transfer::query(), $tenantId, $ownerId)->find($id);
    }

    public function findByIdempotencyKey(string $key, ?string $tenantId, ?string $ownerId): ?Transfer
    {
        return $this->applyScope(Transfer::query(), $tenantId, $ownerId)
            ->where('idempotency_key', $key)
            ->first();
    }

    public function firstOrCreateIdempotent(array $attributes): Transfer
    {
        $attributes = $this->normalizeIdempotencyAttributes($attributes);
        $key = $attributes['idempotency_key'] ?? null;

        if ($key === null) {
            return Transfer::query()->create($attributes);
        }

        return Transfer::query()->firstOrCreate(
            [
                'idempotency_scope' => $attributes['idempotency_scope'],
                'idempotency_key' => $key,
            ],
            $attributes,
        );
    }

    public function save(Transfer $transfer): Transfer
    {
        if ($transfer->exists && $transfer->isDirty('state')) {
            throw new LogicException(
                'Transfer state changes must go through TransferStateMachine::transition().',
            );
        }

        $transfer->save();

        return $transfer->refresh();
    }

    public function claimResumeLease(
        string $transferId,
        string $token,
        DateTimeInterface $now,
        DateTimeInterface $expiresAt,
    ): bool {
        if ($token === '' || strlen($token) > 64) {
            throw new InvalidArgumentException('Resume lease token must contain between 1 and 64 bytes.');
        }

        if ($expiresAt <= $now) {
            throw new InvalidArgumentException('Resume lease expiry must be later than its acquisition time.');
        }

        return Transfer::query()
            ->whereKey($transferId)
            ->whereIn('state', [
                TransferState::PENDING->value,
                TransferState::PREPARING->value,
                TransferState::TRANSFERRING->value,
                TransferState::PAUSED->value,
                TransferState::RETRY_WAIT->value,
                TransferState::VERIFYING->value,
            ])
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('resume_lease_expires_at')
                    ->orWhere('resume_lease_expires_at', '<=', $now);
            })
            ->update([
                'resume_lease_token' => $token,
                'resume_lease_expires_at' => $expiresAt,
                'updated_at' => $now,
            ]) === 1;
    }

    public function renewResumeLease(
        string $transferId,
        string $token,
        DateTimeInterface $now,
        DateTimeInterface $expiresAt,
    ): bool {
        if ($token === '' || strlen($token) > 64) {
            throw new InvalidArgumentException('Resume lease token must contain between 1 and 64 bytes.');
        }

        if ($expiresAt <= $now) {
            throw new InvalidArgumentException('Resume lease expiry must be later than its renewal time.');
        }

        return Transfer::query()
            ->whereKey($transferId)
            ->where('resume_lease_token', $token)
            ->where('resume_lease_expires_at', '>', $now)
            ->update([
                'resume_lease_expires_at' => $expiresAt,
                'updated_at' => $now,
            ]) === 1;
    }

    public function releaseResumeLease(string $transferId, string $token): bool
    {
        if ($token === '' || strlen($token) > 64) {
            throw new InvalidArgumentException('Resume lease token must contain between 1 and 64 bytes.');
        }

        return Transfer::query()
            ->whereKey($transferId)
            ->where('resume_lease_token', $token)
            ->update([
                'resume_lease_token' => null,
                'resume_lease_expires_at' => null,
            ]) === 1;
    }

    public function expiredBefore(DateTimeInterface $cutoff, int $limit = 100): Collection
    {
        return Transfer::query()
            ->whereNotIn('state', [
                TransferState::COMPLETED->value,
                TransferState::FAILED->value,
                TransferState::CANCELLED->value,
                TransferState::EXPIRED->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $cutoff)
            ->orderBy('expires_at')
            ->limit(max(1, min($limit, 1000)))
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeIdempotencyAttributes(array $attributes): array
    {
        $key = $attributes['idempotency_key'] ?? null;

        if ($key === null) {
            $attributes['idempotency_scope'] = null;

            return $attributes;
        }

        if (is_string($key) === false || trim($key) === '') {
            throw new InvalidArgumentException('Idempotency key must be a non-empty string.');
        }

        if (strlen($key) > 128) {
            throw new InvalidArgumentException('Idempotency key must not exceed 128 bytes.');
        }

        $tenantId = isset($attributes['tenant_id']) ? (string) $attributes['tenant_id'] : null;
        $ownerId = isset($attributes['owner_id']) ? (string) $attributes['owner_id'] : null;
        $attributes['idempotency_scope'] = hash(
            'sha256',
            json_encode([$tenantId, $ownerId], JSON_THROW_ON_ERROR),
        );

        return $attributes;
    }

    /**
     * @param  Builder<Transfer>  $query
     * @return Builder<Transfer>
     */
    private function applyScope(Builder $query, ?string $tenantId, ?string $ownerId): Builder
    {
        $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
        $ownerId === null ? $query->whereNull('owner_id') : $query->where('owner_id', $ownerId);

        return $query;
    }
}
