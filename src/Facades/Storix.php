<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Facades;

use Illuminate\Support\Facades\Facade;
use ShamimStack\Storix\StorixManager;

/**
 * @method static \ShamimStack\Storix\Contracts\StorageDriver driver(?string $name = null)
 * @method static \ShamimStack\Storix\DTOs\DriverCapabilities capabilities(?string $name = null)
 * @method static list<string> drivers()
 *
 * @see StorixManager
 */
final class Storix extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'storix';
    }
}
