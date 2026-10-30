<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ShamimStack\Storix\Enums\ObjectVisibility;

final class StoredFile extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'storix_files';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'owner_id',
        'directory_id',
        'driver',
        'name',
        'original_name',
        'extension',
        'mime_type',
        'size_bytes',
        'sha256',
        'storage_key',
        'status',
        'visibility',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'metadata' => 'array',
            'visibility' => ObjectVisibility::class,
        ];
    }

    /** @return BelongsTo<Directory, $this> */
    public function directory(): BelongsTo
    {
        return $this->belongsTo(Directory::class, 'directory_id');
    }

    /** @return HasMany<Transfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'file_id');
    }
}
