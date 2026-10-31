<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ShamimStack\Storix\Enums\FailureCategory;
use ShamimStack\Storix\Exceptions\StorageOperationFailedException;
use ShamimStack\Storix\Exceptions\UploadInProgressException;
use ShamimStack\Storix\Services\ErrorClassifier;
use ShamimStack\Storix\Services\RetryPolicy;

final class RetryPolicyTest extends TestCase
{
    public function test_it_classifies_storage_failures_as_retryable_and_invalid_requests_as_permanent(): void
    {
        $classifier = new ErrorClassifier;

        self::assertSame(
            FailureCategory::TRANSIENT,
            $classifier->classify(new StorageOperationFailedException('multipart_write')),
        );
        self::assertSame(
            FailureCategory::PERMANENT,
            $classifier->classify(new InvalidArgumentException('invalid key')),
        );
        self::assertSame(
            FailureCategory::TRANSIENT,
            $classifier->classify(new UploadInProgressException),
        );
        self::assertSame(
            'UNKNOWN_FAILURE',
            $classifier->codeFor(new RuntimeException('unclassified')),
        );
    }

    public function test_it_preserves_provider_rate_limit_classification_through_wrapped_exceptions(): void
    {
        $rateLimited = new class('provider rate limit') extends RuntimeException
        {
            public function getStatusCode(): int
            {
                return 429;
            }
        };

        $classifier = new ErrorClassifier;

        self::assertSame(
            FailureCategory::RATE_LIMITED,
            $classifier->classify(new StorageOperationFailedException('multipart_write', $rateLimited)),
        );
        self::assertSame(
            'RATE_LIMITED',
            $classifier->codeFor(new StorageOperationFailedException('multipart_write', $rateLimited)),
        );
        self::assertSame(
            'PERMANENT_FAILURE',
            $classifier->codeFor(new StorageOperationFailedException('resolve_path')),
        );
    }

    public function test_it_classifies_persisted_error_codes_for_restart_safe_retries(): void
    {
        $policy = new RetryPolicy(new ErrorClassifier);

        self::assertTrue($policy->shouldRetryCode('STORAGE_OPERATION_FAILED', 1, 5));
        self::assertTrue($policy->shouldRetryCode('RATE_LIMITED', 1, 5));
        self::assertTrue($policy->shouldRetryCode('UNKNOWN_FAILURE', 1, 5));
        self::assertSame(
            FailureCategory::RATE_LIMITED,
            (new ErrorClassifier)->classifyCode('RATE_LIMITED'),
        );
        self::assertFalse($policy->shouldRetryCode('PERMANENT_FAILURE', 1, 5));
        self::assertFalse($policy->shouldRetryCode('UPLOAD_INTEGRITY_FAILED', 1, 5));
        self::assertFalse($policy->shouldRetryCode('STORAGE_OPERATION_FAILED', 5, 5));
    }

    public function test_it_limits_retries_to_the_configured_attempt_count(): void
    {
        $policy = new RetryPolicy(new ErrorClassifier);
        $failure = new StorageOperationFailedException('multipart_write');

        self::assertTrue($policy->shouldRetry($failure, 1, 3));
        self::assertTrue($policy->shouldRetry($failure, 2, 3));
        self::assertFalse($policy->shouldRetry($failure, 3, 3));
        self::assertFalse($policy->shouldRetry($failure, 0, 3));
    }

    public function test_it_does_not_retry_permanent_failures(): void
    {
        $policy = new RetryPolicy(new ErrorClassifier);

        self::assertFalse($policy->shouldRetry(
            new InvalidArgumentException('invalid request'),
            1,
            5,
        ));
    }

    public function test_backoff_is_exponential_capped_and_jittered_within_bounds(): void
    {
        $policy = new RetryPolicy(new ErrorClassifier);

        $first = $policy->delayMilliseconds(1, 2, 10, 20);
        $second = $policy->delayMilliseconds(2, 2, 10, 20);
        $capped = $policy->delayMilliseconds(10, 2, 10, 20);

        self::assertGreaterThanOrEqual(1600, $first);
        self::assertLessThanOrEqual(2400, $first);
        self::assertGreaterThanOrEqual(3200, $second);
        self::assertLessThanOrEqual(4800, $second);
        self::assertGreaterThanOrEqual(8000, $capped);
        self::assertLessThanOrEqual(10000, $capped);
    }
}
