<?php

declare(strict_types=1);

namespace ShamimStack\Storix\Tests\Feature;

use Illuminate\Support\Facades\File;
use ShamimStack\Storix\Contracts\MultipartStorageDriver;
use ShamimStack\Storix\Drivers\Local\LocalStorageDriver;
use ShamimStack\Storix\DTOs\BeginMultipartWriteRequest;
use ShamimStack\Storix\DTOs\CompleteMultipartWriteRequest;
use ShamimStack\Storix\DTOs\ReadStreamRequest;
use ShamimStack\Storix\DTOs\WriteMultipartPartRequest;
use ShamimStack\Storix\Enums\ObjectVisibility;
use ShamimStack\Storix\Exceptions\StorageOperationFailedException;
use ShamimStack\Storix\Tests\TestCase;

final class MultipartStorageDriverTest extends TestCase
{
    private string $root;

    private LocalStorageDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/storix-multipart-driver-'.bin2hex(random_bytes(8));
        $this->driver = new LocalStorageDriver($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_verifies_parts_and_publishes_the_final_object(): void
    {
        $contents = ['first ', 'second'];
        $uploadId = $this->driver->beginMultipartWrite(new BeginMultipartWriteRequest(
            key: 'multipart/final.txt',
            mimeType: 'text/plain',
            visibility: ObjectVisibility::PRIVATE,
        ));
        $parts = [];

        foreach ($contents as $index => $value) {
            $stream = $this->stream($value);

            try {
                $parts[] = $this->driver->writeMultipartPart(new WriteMultipartPartRequest(
                    uploadId: $uploadId,
                    partNumber: $index + 1,
                    stream: $stream,
                ));

                self::assertTrue(is_resource($stream));
            } finally {
                fclose($stream);
            }
        }

        $expected = implode('', $contents);
        $result = $this->driver->completeMultipartWrite(new CompleteMultipartWriteRequest(
            uploadId: $uploadId,
            key: 'multipart/final.txt',
            parts: $parts,
            expectedSizeBytes: strlen($expected),
            expectedSha256: hash('sha256', $expected),
            mimeType: 'text/plain',
        ));

        self::assertSame('multipart/final.txt', $result->object->key);
        self::assertSame(strlen($expected), $result->object->sizeBytes);
        self::assertSame(hash('sha256', $expected), $result->object->checksumSha256);
        self::assertTrue($this->driver->exists('multipart/final.txt'));
        self::assertInstanceOf(MultipartStorageDriver::class, $this->driver);
        self::assertTrue($this->driver->capabilities()->supportsMultipartWrites);
        self::assertSame(1, $this->driver->capabilities()->maxConcurrency);

        $readBack = $this->driver->readStream(new ReadStreamRequest('multipart/final.txt'));

        try {
            self::assertSame($expected, stream_get_contents($readBack));
        } finally {
            fclose($readBack);
        }
    }

    public function test_it_rejects_a_mismatched_final_checksum_and_cleans_the_workspace(): void
    {
        $uploadId = $this->driver->beginMultipartWrite(new BeginMultipartWriteRequest(
            key: 'multipart/invalid.txt',
        ));
        $stream = $this->stream('payload');

        try {
            $part = $this->driver->writeMultipartPart(new WriteMultipartPartRequest(
                uploadId: $uploadId,
                partNumber: 1,
                stream: $stream,
            ));
        } finally {
            fclose($stream);
        }

        try {
            $this->driver->completeMultipartWrite(new CompleteMultipartWriteRequest(
                uploadId: $uploadId,
                key: 'multipart/invalid.txt',
                parts: [$part],
                expectedSizeBytes: strlen('payload'),
                expectedSha256: hash('sha256', 'different'),
            ));

            self::fail('A mismatched aggregate checksum must fail finalization.');
        } catch (StorageOperationFailedException) {
            self::assertFalse($this->driver->exists('multipart/invalid.txt'));
        }

        $lateStream = $this->stream('late');

        try {
            try {
                $this->driver->writeMultipartPart(new WriteMultipartPartRequest(
                    uploadId: $uploadId,
                    partNumber: 2,
                    stream: $lateStream,
                ));

                self::fail('A completed workspace must not accept additional parts.');
            } catch (StorageOperationFailedException) {
                self::assertFalse($this->driver->exists('multipart/invalid.txt'));
            }
        } finally {
            fclose($lateStream);
        }
    }

    public function test_it_binds_a_multipart_session_to_its_initial_object_key(): void
    {
        $uploadId = $this->driver->beginMultipartWrite(new BeginMultipartWriteRequest(
            key: 'multipart/original.txt',
        ));

        $this->expectException(StorageOperationFailedException::class);
        $this->driver->completeMultipartWrite(new CompleteMultipartWriteRequest(
            uploadId: $uploadId,
            key: 'multipart/other.txt',
            parts: [],
            expectedSizeBytes: 0,
            expectedSha256: hash('sha256', ''),
        ));
    }

    public function test_it_aborts_an_incomplete_multipart_workspace_idempotently(): void
    {
        $uploadId = $this->driver->beginMultipartWrite(new BeginMultipartWriteRequest(
            key: 'multipart/aborted.txt',
        ));
        $stream = $this->stream('partial');

        try {
            $this->driver->writeMultipartPart(new WriteMultipartPartRequest(
                uploadId: $uploadId,
                partNumber: 1,
                stream: $stream,
            ));
        } finally {
            fclose($stream);
        }

        $this->driver->abortMultipartWrite($uploadId);
        $this->driver->abortMultipartWrite($uploadId);

        self::assertFalse($this->driver->exists('multipart/aborted.txt'));
    }

    /** @return resource */
    private function stream(string $contents): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }
}
