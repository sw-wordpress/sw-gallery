<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use SWGallery\Core\Contract\MediaImportProviderInterface;
use SWGallery\Tests\Support\InMemoryImportProvider;
use SWGallery\Tests\Support\MediaImportProviderContract;
use SWGallery\WordPress\Import\LocalFileStream;

/** Verifies the reusable provider contract suite itself against the in-memory fake, plus the pure-PHP file stream. */
final class ImportProviderContractTest extends MediaImportProviderContract
{
    private InMemoryImportProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new InMemoryImportProvider();
    }

    protected function createProvider(): MediaImportProviderInterface
    {
        return $this->provider;
    }

    protected function seed(int $count): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; ++$i) {
            $id = (string) ($i * 3);
            $this->provider->add($id, str_repeat('x' . $id, $i));
            $ids[] = $id;
        }

        return $ids;
    }

    protected function unknownRemoteId(): string
    {
        return '999999';
    }

    protected function expectedBytes(string $remoteId): string
    {
        return str_repeat('x' . $remoteId, intdiv((int) $remoteId, 3));
    }

    public function testLocalFileStreamReadsBoundedAndClosesIdempotently(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'swg');
        self::assertIsString($path);
        file_put_contents($path, str_repeat('abc', 100));
        try {
            $handle = fopen($path, 'rb');
            self::assertIsResource($handle);
            $stream = new LocalFileStream($handle);
            self::assertFalse($stream->isEof());
            self::assertSame('', $stream->read(0));
            self::assertSame(4, strlen($stream->read(4)));
            $data = '';
            while (!$stream->isEof()) {
                $data .= $stream->read(10_000_000);
            }
            self::assertSame(296, strlen($data));
            self::assertSame('', $stream->read(5));
            $stream->close();
            $stream->close();
            self::assertTrue($stream->isEof());
            self::assertSame('', $stream->read(5));
        } finally {
            unlink($path);
        }
    }

    public function testLocalFileStreamRejectsNonResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LocalFileStream('nope');
    }
}
