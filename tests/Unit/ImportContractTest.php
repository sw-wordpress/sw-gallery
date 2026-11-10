<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWGallery\Core\Domain\Import\FolderPage;
use SWGallery\Core\Domain\Import\ImportCapabilities;
use SWGallery\Core\Domain\Import\ImportConnection;
use SWGallery\Core\Domain\Import\ImportOutcome;
use SWGallery\Core\Domain\Import\ImportResult;
use SWGallery\Core\Domain\Import\ImportSource;
use SWGallery\Core\Domain\Import\ImportSourceStatus;
use SWGallery\Core\Domain\Import\ImportStatus;
use SWGallery\Core\Domain\Import\RemoteFolder;
use SWGallery\Core\Domain\Import\RemoteMediaObject;
use SWGallery\Core\Domain\Import\RemoteMediaPage;
use SWGallery\Core\Exception\ImportProviderException;

final class ImportContractTest extends TestCase
{
    public function testResultCountsAreDerivedAndHonest(): void
    {
        $result = new ImportResult([
            new ImportOutcome('a', ImportStatus::Imported, 11),
            new ImportOutcome('b', ImportStatus::Imported, 12),
            new ImportOutcome('c', ImportStatus::Skipped),
            new ImportOutcome('d', ImportStatus::Duplicate, 5),
            new ImportOutcome('e', ImportStatus::Failed, null, 'swg_download_failed'),
            new ImportOutcome('f', ImportStatus::Unsupported, null, 'swg_unsupported_type'),
        ]);

        self::assertSame(6, $result->total());
        self::assertSame(2, $result->count(ImportStatus::Imported));
        self::assertSame(1, $result->count(ImportStatus::Failed));
        self::assertSame([11, 12], $result->importedAttachmentIds());
        self::assertFalse($result->isFullySuccessful());
    }

    public function testResultWithoutFailuresIsFullySuccessful(): void
    {
        $result = new ImportResult([
            new ImportOutcome('a', ImportStatus::Imported, 1),
            new ImportOutcome('b', ImportStatus::Skipped),
        ]);
        self::assertTrue($result->isFullySuccessful());
        self::assertTrue((new ImportResult([]))->isFullySuccessful());
    }

    /** @return iterable<string, array{callable(): object}> */
    public static function invalidObjects(): iterable
    {
        yield 'outcome empty id' => [static fn () => new ImportOutcome('', ImportStatus::Skipped)];
        yield 'outcome bad attachment' => [static fn () => new ImportOutcome('a', ImportStatus::Duplicate, 0)];
        yield 'imported without attachment' => [static fn () => new ImportOutcome('a', ImportStatus::Imported)];
        yield 'failed without code' => [static fn () => new ImportOutcome('a', ImportStatus::Failed)];
        yield 'unsupported without code' => [static fn () => new ImportOutcome('a', ImportStatus::Unsupported)];
        yield 'source bad id' => [static fn () => new ImportSource(0, 'p', 'l', 'acc', 's', [], ImportSourceStatus::Active, 1)];
        yield 'source no provider' => [static fn () => new ImportSource(null, '', 'l', 'acc', 's', [], ImportSourceStatus::Active, 1)];
        yield 'source no label' => [static fn () => new ImportSource(null, 'p', '', 'acc', 's', [], ImportSourceStatus::Active, 1)];
        yield 'source no account' => [static fn () => new ImportSource(null, 'p', 'l', '', 's', [], ImportSourceStatus::Active, 1)];
        yield 'source bad creator' => [static fn () => new ImportSource(null, 'p', 'l', 'acc', 's', [], ImportSourceStatus::Active, -1)];
        yield 'connection bad source' => [static fn () => new ImportConnection(0, 'p', 'acc', 's')];
        yield 'connection no provider' => [static fn () => new ImportConnection(1, '', 'acc', 's')];
        yield 'connection no account' => [static fn () => new ImportConnection(1, 'p', '', 's')];
        yield 'remote id empty' => [static fn () => new RemoteMediaObject('', 'n', 'image/png', 1)];
        yield 'remote id too long' => [static fn () => new RemoteMediaObject(str_repeat('x', 192), 'n', 'image/png', 1)];
        yield 'remote size negative' => [static fn () => new RemoteMediaObject('id', 'n', 'image/png', -1)];
        yield 'folder id empty' => [static fn () => new RemoteFolder('', 'n')];
        yield 'folder id too long' => [static fn () => new RemoteFolder(str_repeat('x', 192), 'n')];
    }

    /** @param callable(): object $factory */
    #[DataProvider('invalidObjects')]
    public function testInvalidObjectsAreRejected(callable $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $factory();
    }

    public function testRemoteIdentityIsTheIdNotTheName(): void
    {
        $a = new RemoteMediaObject('id-1', 'photo.jpg', 'image/jpeg', 10, 'r1', 'e1', new \DateTimeImmutable('2026-01-01'), '/a/photo.jpg', 100, 50);
        $b = new RemoteMediaObject('id-2', 'photo.jpg', 'image/jpeg', 10);

        self::assertNotSame($a->remoteObjectId, $b->remoteObjectId);
        self::assertSame('photo.jpg', $b->name);
        self::assertNull($b->revision);
        self::assertSame(100, $a->width);
    }

    public function testPagesAndCapabilitiesAreSimpleCarriers(): void
    {
        $folders = new FolderPage([new RemoteFolder('f1', 'Folder', true)], 'next');
        $media = new RemoteMediaPage([new RemoteMediaObject('m1', 'n', 'image/png', 1)]);
        $caps = new ImportCapabilities(browseFolders: true, revisionTracking: true);
        $source = new ImportSource(3, 'drive', 'Label', 'acc', 'scope', ['folder' => 'x'], ImportSourceStatus::Active, 1);
        $connection = new ImportConnection(3, 'drive', 'acc', 'scope');

        self::assertSame('next', $folders->nextCursor);
        self::assertNull($media->nextCursor);
        self::assertTrue($caps->browseFolders);
        self::assertFalse($caps->recursiveListing);
        self::assertSame(['folder' => 'x'], $source->config);
        self::assertSame(3, $connection->sourceId);
    }

    public function testProviderExceptionCarriesStableCodeAndRetryability(): void
    {
        $transient = new ImportProviderException('swg_rate_limited', true);
        $permanent = new ImportProviderException('swg_access_revoked', false, 'Access was revoked.');

        self::assertSame('swg_rate_limited', $transient->errorCode());
        self::assertTrue($transient->isTransient());
        self::assertFalse($permanent->isTransient());
        self::assertSame('Access was revoked.', $permanent->getMessage());
    }
}
