<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Services;

use InvalidArgumentException;
use LogicException;
use PDOException;
use ShamimStack\Storix\Enums\FailureCategory;
use ShamimStack\Storix\Enums\StorixErrorCode;
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
     * Classify an exception chain without exposing provider messages or credentials.
     */
    public function classify(Throwable $exception): FailureCategory
    {
        $status = $this->httpStatus($exception);

        if ($status === 429) {
            return FailureCategory::RATE_LIMITED;
        }

        if ($status !== null) {
            if (in_array($status, [408, 425, 500, 502, 503, 504], true)) {
                return FailureCategory::TRANSIENT;
            }

            if ($status >= 400 && $status < 500) {
                return FailureCategory::PERMANENT;
            }
        }

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

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException) {
                $sqlState = is_array($current->errorInfo ?? null)
                    ? (string) ($current->errorInfo[0] ?? '')
                    : (string) $current->getCode();

                if (str_starts_with($sqlState, '08') || str_starts_with($sqlState, '40')) {
                    return FailureCategory::TRANSIENT;
                }
            }
        }

        if ($exception instanceof StorageOperationFailedException) {
            return in_array($exception->operation, self::PERMANENT_STORAGE_OPERATIONS, true)
                ? FailureCategory::PERMANENT
                : FailureCategory::TRANSIENT;
        }

        if ($exception instanceof StorixException) {
            return FailureCategory::PERMANENT;
        }

        return FailureCategory::UNKNOWN;
    }

    /**
     * Map an exception to a stable code suitable for persistence on a transfer.
     */
    public function codeFor(Throwable $exception): string
    {
        $category = $this->classify($exception);

        if ($category === FailureCategory::RATE_LIMITED) {
            return StorixErrorCode::RATE_LIMITED->value;
        }

        if ($exception instanceof StorixException) {
            if (
                $exception instanceof StorageOperationFailedException
                && $category === FailureCategory::PERMANENT
            ) {
                return StorixErrorCode::PERMANENT_FAILURE->value;
            }

            return $exception->errorCode->value;
        }

        return $category === FailureCategory::PERMANENT
            ? StorixErrorCode::PERMANENT_FAILURE->value
            : StorixErrorCode::STORAGE_OPERATION_FAILED->value;
    }

    /**
     * Classify the stable error code persisted on a transfer for restart-safe retry decisions.
     */
    public function classifyCode(?string $errorCode): FailureCategory
    {
        if ($errorCode === null || trim($errorCode) === '') {
            return FailureCategory::UNKNOWN;
        }

        return match ($errorCode) {
            StorixErrorCode::STORAGE_OPERATION_FAILED->value,
            StorixErrorCode::UPLOAD_IN_PROGRESS->value => FailureCategory::TRANSIENT,
            StorixErrorCode::RATE_LIMITED->value => FailureCategory::RATE_LIMITED,

            StorixErrorCode::DRIVER_NOT_FOUND->value,
            StorixErrorCode::INVALID_REQUEST->value,
            StorixErrorCode::UNSUPPORTED_CAPABILITY->value,
            StorixErrorCode::UPLOAD_TOO_LARGE->value,
            StorixErrorCode::IDEMPOTENCY_CONFLICT->value,
            StorixErrorCode::UPLOAD_INTEGRITY_FAILED->value,
            StorixErrorCode::TRANSFER_EXPIRED->value,
            StorixErrorCode::MAX_RETRIES_EXCEEDED->value,
            StorixErrorCode::PERMANENT_FAILURE->value => FailureCategory::PERMANENT,

            default => FailureCategory::UNKNOWN,
        };
    }

    private function httpStatus(Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            try {
                if (method_exists($current, 'getStatusCode')) {
                    $status = (int) $current->getStatusCode();

                    if ($status >= 100 && $status <= 599) {
                        return $status;
                    }
                }

                if (method_exists($current, 'getResponse')) {
                    $response = $current->getResponse();

                    if (is_object($response) && method_exists($response, 'getStatusCode')) {
                        $status = (int) $response->getStatusCode();

                        if ($status >= 100 && $status <= 599) {
                            return $status;
                        }
                    }

                    if (is_object($response) && method_exists($response, 'status')) {
                        $status = (int) $response->status();

                        if ($status >= 100 && $status <= 599) {
                            return $status;
                        }
                    }
                }
            } catch (Throwable) {
                // A broken provider response object must not mask the original exception.
            }
        }

        return null;
    }
}
