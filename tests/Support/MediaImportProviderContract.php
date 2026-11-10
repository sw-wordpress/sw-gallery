<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use PHPUnit\Framework\TestCase;
use SWGallery\Core\Contract\MediaImportProviderInterface;
use SWGallery\Core\Domain\Import\ImportConnection;
use SWGallery\Core\Exception\ImportProviderException;

/**
 * Behavioral contract every MediaImportProviderInterface implementation must satisfy. Subclasses supply the
 * provider and fixtures; run the suite against each new provider. Each test seeds its own fixtures; the fixture
 * must start empty for every test.
 */
abstract class MediaImportProviderContract extends TestCase
{
    abstract protected function createProvider(): MediaImportProviderInterface;

    /**
     * Creates $count importable media in the fixture and returns their remote ids in expected listing order.
     *
     * @return list<string>
     */
    abstract protected function seed(int $count): array;

    /** A well-formed remote id that does not exist. */
    abstract protected function unknownRemoteId(): string;

    /** Exact bytes the download stream must yield for a seeded id. */
    abstract protected function expectedBytes(string $remoteId): string;

    protected function connection(MediaImportProviderInterface $provider): ImportConnection
    {
        return new ImportConnection(1, $provider->getProviderId(), 'account', 'scope');
    }

    /** @return list<string> */
    private function listAll(MediaImportProviderInterface $provider, int $limit): array
    {
        $connection = $this->connection($provider);
        $ids = [];
        $cursor = null;
        for ($guard = 0; $guard < 1000; ++$guard) {
            $page = $provider->listMedia($connection, null, $cursor, $limit);
            self::assertLessThanOrEqual($limit, count($page->objects));
            foreach ($page->objects as $object) {
                $ids[] = $object->remoteObjectId;
            }
            $cursor = $page->nextCursor;
            if ($cursor === null) {
                return $ids;
            }
            self::assertNotSame([], $page->objects, 'A non-final page must not be empty.');
        }
        self::fail('Pagination did not terminate.');
    }

    public function testProviderIdIsStableAndNotEmpty(): void
    {
        $provider = $this->createProvider();
        self::assertNotSame('', $provider->getProviderId());
        self::assertSame($provider->getProviderId(), $provider->getProviderId());
    }

    public function testCursorPaginationCoversEverythingOnceInStableOrder(): void
    {
        $expected = $this->seed(7);
        $provider = $this->createProvider();

        self::assertSame($expected, $this->listAll($provider, 3));
        self::assertSame($expected, $this->listAll($provider, 3), 'Order must be stable between runs.');
        self::assertSame($expected, $this->listAll($provider, 100));
    }

    public function testLastPageHasNullCursorAndExactFitHasNoPhantomPage(): void
    {
        $this->seed(4);
        $provider = $this->createProvider();
        $connection = $this->connection($provider);

        $page = $provider->listMedia($connection, null, null, 4);
        self::assertCount(4, $page->objects);
        self::assertNull($page->nextCursor);

        $first = $provider->listMedia($connection, null, null, 3);
        self::assertNotNull($first->nextCursor);
        $second = $provider->listMedia($connection, null, $first->nextCursor, 3);
        self::assertCount(1, $second->objects);
        self::assertNull($second->nextCursor);
    }

    public function testEmptyListingHasNoCursor(): void
    {
        $provider = $this->createProvider();
        $page = $provider->listMedia($this->connection($provider), null, null, 10);
        self::assertSame([], $page->objects);
        self::assertNull($page->nextCursor);
    }

    public function testPageSizeIsBounded(): void
    {
        $this->seed(MediaImportProviderInterface::MAX_PAGE_SIZE + 5);
        $provider = $this->createProvider();
        $connection = $this->connection($provider);

        $huge = $provider->listMedia($connection, null, null, 100000);
        self::assertLessThanOrEqual(MediaImportProviderInterface::MAX_PAGE_SIZE, count($huge->objects));
        self::assertNotNull($huge->nextCursor);
        $tiny = $provider->listMedia($connection, null, null, 0);
        self::assertLessThanOrEqual(1, count($tiny->objects));
        $negative = $provider->listMedia($connection, null, null, -5);
        self::assertLessThanOrEqual(1, count($negative->objects));
    }

    public function testInvalidCursorIsPermanentFailure(): void
    {
        $this->seed(2);
        $provider = $this->createProvider();
        try {
            $provider->listMedia($this->connection($provider), null, 'not-a-cursor!', 10);
            self::fail('Expected an ImportProviderException.');
        } catch (ImportProviderException $e) {
            self::assertFalse($e->isTransient());
            self::assertNotSame('', $e->errorCode());
        }
    }

    public function testMetadataForKnownIdMatchesListing(): void
    {
        $ids = $this->seed(2);
        $provider = $this->createProvider();
        $connection = $this->connection($provider);

        $listed = $provider->listMedia($connection, null, null, 10)->objects[0];
        $meta = $provider->getMediaMetadata($connection, $ids[0]);
        self::assertSame($ids[0], $meta->remoteObjectId);
        self::assertSame($listed->remoteObjectId, $meta->remoteObjectId);
        self::assertSame($listed->mimeType, $meta->mimeType);
        self::assertSame(strlen($this->expectedBytes($ids[0])), $meta->sizeBytes);
        self::assertStringStartsWith('image/', $meta->mimeType);
    }

    public function testUnknownIdIsPermanentFailureForMetadataAndStream(): void
    {
        $this->seed(1);
        $provider = $this->createProvider();
        $connection = $this->connection($provider);

        $calls = [
            fn () => $provider->getMediaMetadata($connection, $this->unknownRemoteId()),
            fn () => $provider->openDownloadStream($connection, $this->unknownRemoteId()),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Expected an ImportProviderException.');
            } catch (ImportProviderException $e) {
                self::assertFalse($e->isTransient());
                self::assertNotSame('', $e->errorCode());
            }
        }
    }

    public function testStreamReadsBoundedChunksToEofAndCloseIsIdempotent(): void
    {
        $ids = $this->seed(1);
        $provider = $this->createProvider();
        $expected = $this->expectedBytes($ids[0]);
        $stream = $provider->openDownloadStream($this->connection($provider), $ids[0]);

        $data = '';
        $reads = 0;
        while (!$stream->isEof() && $reads++ < 100000) {
            $chunk = $stream->read(7);
            self::assertLessThanOrEqual(7, strlen($chunk));
            $data .= $chunk;
        }
        self::assertSame($expected, $data);
        self::assertTrue($stream->isEof());
        self::assertSame('', $stream->read(7), 'Reading at EOF yields an empty string.');

        $stream->close();
        $stream->close();
        self::assertSame('', $stream->read(7), 'Reading a closed stream yields an empty string.');
    }

    public function testCapabilitiesAreConsistentWithBehavior(): void
    {
        $this->seed(2);
        $provider = $this->createProvider();
        $connection = $this->connection($provider);
        $caps = $provider->getCapabilities();

        if (!$caps->browseFolders) {
            self::assertSame([], $provider->listFolders($connection, null, null, 10)->folders);
            try {
                $provider->listMedia($connection, 'some-folder', null, 10);
                self::fail('A flat provider must reject folder-scoped listing.');
            } catch (ImportProviderException $e) {
                self::assertFalse($e->isTransient());
            }
            self::assertNotSame([], $provider->listMedia($connection, null, null, 10)->objects);
        }
    }
}
