<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Services;

use ShamimStack\Storix\Enums\FailureCategory;
use Throwable;

final class RetryPolicy
{
    public function __construct(private readonly ErrorClassifier $classifier) {}

    /**
     * Return whether another attempt is allowed under the configured hard attempt limit.
     */
    public function shouldRetry(Throwable $exception, int $attempt, int $maxAttempts): bool
    {
        if ($attempt < 1 || $maxAttempts < 1 || $attempt >= $maxAttempts) {
            return false;
        }

        return $this->isRetryableCategory($this->classifier->classify($exception));
    }

    /**
     * Apply the same bounded retry rule to an error code restored from persistent storage.
     */
    public function shouldRetryCode(?string $errorCode, int $attempt, int $maxAttempts): bool
    {
        if ($attempt < 1 || $maxAttempts < 1 || $attempt >= $maxAttempts) {
            return false;
        }

        return $this->isRetryableCategory($this->classifier->classifyCode($errorCode));
    }

    /**
     * Calculate capped exponential backoff with bounded full-width jitter.
     */
    public function delayMilliseconds(
        int $attempt,
        int $baseSeconds,
        int $maximumSeconds,
        int $jitterPercent = 20,
    ): int {
        $baseMilliseconds = max(1, $baseSeconds) * 1000;
        $maximumMilliseconds = max(max(1, $baseSeconds), $maximumSeconds) * 1000;
        $exponent = min(20, max(0, $attempt - 1));
        $baseDelay = min($maximumMilliseconds, $baseMilliseconds * (2 ** $exponent));
        $spread = (int) floor($baseDelay * min(100, max(0, $jitterPercent)) / 100);
        $minimum = max(0, (int) $baseDelay - $spread);
        $maximum = min($maximumMilliseconds, (int) $baseDelay + $spread);

        return random_int($minimum, max($minimum, $maximum));
    }

    private function isRetryableCategory(FailureCategory $category): bool
    {
        return in_array($category, [
            FailureCategory::TRANSIENT,
            FailureCategory::RATE_LIMITED,
            FailureCategory::UNKNOWN,
        ], true);
    }
}
