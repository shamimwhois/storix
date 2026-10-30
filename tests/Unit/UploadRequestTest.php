<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ShamimStack\Storix\DTOs\UploadRequest;

final class UploadRequestTest extends TestCase
{
    public function test_it_accepts_a_readable_stream_and_normalizes_filename(): void
    {
        $stream = fopen('php://temp', 'r+');

        try {
            $request = new UploadRequest(
                stream: $stream,
                originalName: '  report.pdf  ',
                tenantId: ' tenant-a ',
                ownerId: ' owner-a ',
                idempotencyKey: ' request-1 ',
            );

            self::assertSame('report.pdf', $request->originalName);
            self::assertSame('tenant-a', $request->tenantId);
            self::assertSame('owner-a', $request->ownerId);
            self::assertSame('request-1', $request->idempotencyKey);
            self::assertSame($stream, $request->stream);
        } finally {
            fclose($stream);
        }
    }

    public function test_it_rejects_invalid_streams(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UploadRequest(stream: 'not-a-stream', originalName: 'report.pdf');
    }

    public function test_it_rejects_write_only_streams(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'storix-upload-');
        self::assertNotFalse($path);
        $stream = fopen($path, 'w');

        try {
            $this->expectException(InvalidArgumentException::class);
            new UploadRequest(stream: $stream, originalName: 'report.pdf');
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public function test_it_rejects_path_components_in_user_filenames(): void
    {
        $stream = fopen('php://temp', 'r+');

        try {
            $this->expectException(InvalidArgumentException::class);
            new UploadRequest(stream: $stream, originalName: '../report.pdf');
        } finally {
            fclose($stream);
        }
    }

    public function test_it_rejects_invalid_scope_and_idempotency_values(): void
    {
        $stream = fopen('php://temp', 'r+');

        try {
            $this->expectException(InvalidArgumentException::class);
            new UploadRequest(
                stream: $stream,
                originalName: 'report.pdf',
                ownerId: str_repeat('x', 65),
            );
        } finally {
            fclose($stream);
        }
    }
}
