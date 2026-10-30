<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\StorageObjectMetadata;
use ShamimStack\Storix\DTOs\WriteStreamRequest;
use ShamimStack\Storix\Enums\ObjectVisibility;

final class StorageDtoTest extends TestCase
{
    public function test_read_request_rejects_negative_offsets(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReadStreamRequest('documents/report.pdf', offset: -1);
    }

    public function test_read_request_rejects_zero_length(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReadStreamRequest('documents/report.pdf', length: 0);
    }

    public function test_write_request_requires_a_stream_resource(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WriteStreamRequest('documents/report.pdf', 'not-a-stream');
    }

    public function test_metadata_rejects_invalid_sha256_checksum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StorageObjectMetadata(
            key: 'documents/report.pdf',
            sizeBytes: 10,
            checksumSha256: 'invalid',
            visibility: ObjectVisibility::PRIVATE,
        );
    }

    public function test_write_request_accepts_a_valid_stream(): void
    {
        $stream = fopen('php://temp', 'r+');

        try {
            $request = new WriteStreamRequest(
                key: 'documents/report.pdf',
                stream: $stream,
                visibility: ObjectVisibility::PRIVATE,
            );

            self::assertSame($stream, $request->stream);
        } finally {
            fclose($stream);
        }
    }
}
