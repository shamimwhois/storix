<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Repositories\Eloquent;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use ShamimStack\Storix\Contracts\Repositories\TransferRepository;
use ShamimStack\Storix\Enums\TransferState;
use ShamimStack\Storix\Models\Transfer;

final class EloquentTransferRepository implements TransferRepository
{
    public function create(array $attributes): Transfer
    {
        return Transfer::query()->create($attributes);
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
        $key = $attributes['idempotency_key'] ?? null;

        if ($key === null) {
            return $this->create($attributes);
        }

        $tenantId = $attributes['tenant_id'] ?? null;
        $ownerId = $attributes['owner_id'] ?? null;
        $scopeHash = hash('sha256', json_encode([$tenantId, $ownerId], JSON_THROW_ON_ERROR));
        $attributes['idempotency_scope'] = $scopeHash;

        return Transfer::query()->firstOrCreate(
            ['idempotency_scope' => $scopeHash, 'idempotency_key' => $key],
            $attributes,
        );
    }

    public function save(Transfer $transfer): Transfer
    {
        $transfer->save();

        return $transfer->refresh();
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
