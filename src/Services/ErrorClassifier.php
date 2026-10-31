<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Services;

use InvalidArgumentException;
use LogicException;
use PDOException;
use ShamimStack\Storix\Enums\FailureCategory;
use ShamimStack\Storix\Exceptions\IdempotencyConflictException;
use ShamimStack\Storix\Exceptions\StorageOperationFailedException;
use ShamimStack\Storix\Exceptions\StorixException;
use ShamimStack\Storix\Exceptions\UploadInProgressException;
use ShamimStack\Storix\Exceptions\UploadIntegrityException;
use ShamimStack\Storix\Exceptions\UploadTooLargeException;
use ShamimStack\Storix\Exceptions\UploadValidationException;
use ShamimStack\Storix\Exceptions\UnsupportedCapabilityException;
use Throwable;
use TypeError;

final class ErrorClassifier
{
    private const array PERMANENT_STORAGE_OPERATIONS = [
        'create_directory',
        'create_root',
        'delete',
        'delete_directory',
        'multipart_cleanup',
        'multipart_discard',
        'multipart_verify',
        'multipart_workspace',
        'resolve_path',
        'resolve_root',
    ];

    /**
     * Classify an exception without exposing provider messages or credentials.
     */
    public function classify(Throwable $exception): FailureCategory
    {
        if (
            $exception instanceof InvalidArgumentException
            || $exception instanceof LogicException
            || $exception instanceof TypeError
            || $exception instanceof IdempotencyConflictException
            || $exception instanceof UploadInProgressException
            || $exception instanceof UploadIntegrityException
            || $exception instanceof UploadTooLargeException
            || $exception instanceof UploadValidationException
            || $exception instanceof UnsupportedCapabilityException
        ) {
            return FailureCategory::PERMANENT;
        }

        if ($exception instanceof StorageOperationFailedException) {
            return in_array($exception->operation, self::PERMANENT_STORAGE_OPERATIONS, true)
                ? FailureCategory::PERMANENT
                : FailureCategory::TRANSIENT;
        }

        if ($this->httpStatus($exception) === 429 || (int) $exception->getCode() === 429) {
            return FailureCategory::RATE_LIMITED;
        }

        if ($exception instanceof PDOException) {
            $sqlState = is_array($exception->errorInfo ?? null)
                ? (string) ($exception->errorInfo[0] ?? '')
                : (string) $exception->getCode();

            if (str_starts_with($sqlState, '08') || str_starts_with($sqlState, '40')) {
                return FailureCategory::TRANSIENT;
            }
        }

        if ($exception instanceof StorixException) {
            return FailureCategory::PERMANENT;
        }

        return FailureCategory::UNKNOWN;
    }

    private function httpStatus(Throwable $exception): ?int
    {
        if (method_exists($exception, 'getResponse') === false) {
            return null;
        }

        try {
            $response = $exception->getResponse();

            if (is_object($response) && method_exists($response, 'status')) {
                return (int) $response->status();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
