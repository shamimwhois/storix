<?php

declare(strict_types=1);

namespace ShamimStack\Storix\DTOs;

use ShamimStack\Storix\Models\StoredFile;
use ShamimStack\Storix\Models\Transfer;

final readonly class UploadResult
{
    public function __construct(
        public StoredFile $file,
        public Transfer $transfer,
        public bool $replayed = false,
    ) {}
}
