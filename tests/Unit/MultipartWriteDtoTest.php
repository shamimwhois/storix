<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ShamimStack\Storix\DTOs\CompleteMultipartWriteRequest;
use ShamimStack\Storix\DTOs\MultipartPartResult;

final class MultipartWriteDtoTest extends TestCase
{
    public function test_complete_request_requires_sequential_part_numbers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CompleteMultipartWriteRequest(
            uploadId: 'session-1',
            key: 'object.bin',
            parts: [new MultipartPartResult(2, 4, hash('sha256', 'data'))],
            expectedSizeBytes: 4,
            expectedSha256: hash('sha256', 'data'),
        );
    }

    public function test_complete_request_accepts_an_empty_object_without_parts(): void
    {
        $request = new CompleteMultipartWriteRequest(
            uploadId: 'session-2',
            key: 'empty.bin',
            parts: [],
            expectedSizeBytes: 0,
            expectedSha256: hash('sha256', ''),
        );

        self::assertSame([], $request->parts);
        self::assertSame(0, $request->expectedSizeBytes);
    }
}
