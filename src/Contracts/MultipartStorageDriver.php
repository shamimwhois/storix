<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Contracts;

use ShamimStack\Storix\DTOs\BeginMultipartWriteRequest;
use ShamimStack\Storix\DTOs\CompleteMultipartWriteRequest;
use ShamimStack\Storix\DTOs\MultipartPartResult;
use ShamimStack\Storix\DTOs\WriteMultipartPartRequest;
use ShamimStack\Storix\DTOs\WriteResult;

interface MultipartStorageDriver extends StorageDriver
{
    public function beginMultipartWrite(BeginMultipartWriteRequest $request): string;

    public function writeMultipartPart(WriteMultipartPartRequest $request): MultipartPartResult;

    public function inspectMultipartPart(string $uploadId, int $partNumber): ?MultipartPartResult;

    public function discardMultipartPart(string $uploadId, int $partNumber): void;

    public function completeMultipartWrite(CompleteMultipartWriteRequest $request): WriteResult;

    public function abortMultipartWrite(string $uploadId): void;
}
