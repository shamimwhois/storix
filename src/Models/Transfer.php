<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ShamimStack\Storix\Enums\TransferDirection;
use ShamimStack\Storix\Enums\TransferState;

final class Transfer extends Model
{
    use HasUuids;

    protected $table = 'storix_transfers';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'file_id',
        'tenant_id',
        'owner_id',
        'direction',
        'driver',
        'state',
        'source_name',
        'total_bytes',
        'transferred_bytes',
        'total_chunks',
        'completed_chunks',
        'average_bytes_per_second',
        'eta_seconds',
        'idempotency_key',
        'idempotency_scope',
        'fingerprint',
        'multipart_upload_id',
        'multipart_started_at',
        'attempts',
        'max_attempts',
        'last_error_code',
        'last_error_message',
        'started_at',
        'finished_at',
        'expires_at',
    ];

    protected $hidden = [
        'multipart_upload_id',
    ];

    protected function casts(): array
    {
        return [
            'direction' => TransferDirection::class,
            'state' => TransferState::class,
            'total_bytes' => 'integer',
            'transferred_bytes' => 'integer',
            'total_chunks' => 'integer',
            'completed_chunks' => 'integer',
            'average_bytes_per_second' => 'decimal:2',
            'eta_seconds' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'multipart_started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<StoredFile, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    /** @return HasMany<Chunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class, 'transfer_id');
    }
}
