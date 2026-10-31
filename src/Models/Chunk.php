<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ShamimStack\Storix\Enums\ChunkState;

final class Chunk extends Model
{
    use HasUuids;

    protected $table = 'storix_chunks';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'transfer_id',
        'chunk_index',
        'offset_bytes',
        'size_bytes',
        'sha256',
        'state',
        'driver_reference',
        'attempts',
        'last_error_code',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'offset_bytes' => 'integer',
            'size_bytes' => 'integer',
            'state' => ChunkState::class,
            'attempts' => 'integer',
            'verified_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Transfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class, 'transfer_id');
    }
}
