<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class Directory extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'storix_directories';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'owner_id',
        'parent_id',
        'name',
        'path_key',
        'driver',
        'status',
    ];

    protected static function booted(): void
    {
        self::saving(function (Directory $directory): void {
            $name = trim((string) $directory->name);

            if ($name === '') {
                throw new InvalidArgumentException('Directory name must be a non-empty string.');
            }

            $directory->name = $name;
            $directory->scope_hash = hash('sha256', json_encode([
                $directory->tenant_id,
                $directory->owner_id,
                $directory->driver,
                $directory->parent_id,
            ], JSON_THROW_ON_ERROR));
            $directory->name_hash = hash('sha256', Str::lower($name));
        });
    }

    /** @return BelongsTo<Directory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Directory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<StoredFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'directory_id');
    }
}
