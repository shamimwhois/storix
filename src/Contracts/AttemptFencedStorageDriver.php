<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Contracts;

use ShamimStack\Storix\DTOs\BeginMultipartWriteRequest;
use ShamimStack\Storix\DTOs\CompleteMultipartWriteRequest;
use ShamimStack\Storix\DTOs\DeleteObjectRequest;
use ShamimStack\Storix\DTOs\MultipartPartResult;
use ShamimStack\Storix\DTOs\WriteMultipartPartRequest;
use ShamimStack\Storix\DTOs\WriteResult;
use ShamimStack\Storix\DTOs\WriteStreamRequest;

/**
 * Storage adapter contract for immutable, attempt-scoped provider operations.
 *
 * Implementations MUST isolate every object and multipart session by attempt ID.
 * The application commits the returned immutable object key as the winning
 * StoredFile reference inside its lease-and-generation guarded database transaction.
 */
interface AttemptFencedStorageDriver extends StorageDriver
{
    /**
     * Return the immutable object key owned exclusively by this attempt.
     */
    public function attemptObjectKey(string $logicalKey, string $attemptId): string;

    /**
     * Write an object only inside the supplied attempt namespace.
     */
    public function writeAttempt(WriteStreamRequest $request, string $attemptId): WriteResult;

    /**
     * Begin a multipart session bound to this attempt namespace.
     */
    public function beginMultipartAttempt(BeginMultipartWriteRequest $request, string $attemptId): string;

    /**
     * Write a part only to a multipart session owned by this attempt.
     */
    public function writeMultipartAttemptPart(WriteMultipartPartRequest $request, string $attemptId): MultipartPartResult;

    /**
     * Complete a multipart session only into this attempt's immutable object key.
     */
    public function completeMultipartAttempt(CompleteMultipartWriteRequest $request, string $attemptId): WriteResult;

    /**
     * Abort only a multipart session proven to belong to this attempt.
     */
    public function abortMultipartAttempt(string $uploadId, string $attemptId): void;

    /**
     * Delete only an object whose key is inside this attempt's namespace.
     */
    public function deleteAttempt(DeleteObjectRequest $request, string $attemptId): void;
}
