<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryPage;
use SWGallery\Core\Domain\Gallery\GalleryQuery;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Core\Domain\Gallery\SortMode;

final class GalleryDomainTest extends TestCase
{
    private function gallery(): Gallery
    {
        return new Gallery(
            3,
            'Title',
            'Desc',
            GalleryStatus::Draft,
            5,
            null,
            new GallerySettings(),
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            true
        );
    }

    public function testWithersReturnNewInstancesAndKeepEverythingElse(): void
    {
        $g = $this->gallery();
        $changed = $g->withTitle('T2')->withDescription('D2')->withStatus(GalleryStatus::Publish)
            ->withOwner(8)->withCover(12)->withSettings(new GallerySettings('masonry'));

        self::assertSame('Title', $g->title);
        self::assertSame(GalleryStatus::Draft, $g->status);
        self::assertSame(3, $changed->id);
        self::assertSame('T2', $changed->title);
        self::assertSame('D2', $changed->description);
        self::assertSame(GalleryStatus::Publish, $changed->status);
        self::assertSame(8, $changed->ownerId);
        self::assertSame(12, $changed->coverAttachmentId);
        self::assertSame('masonry', $changed->settings->layout);
        self::assertTrue($changed->passwordProtected);
        self::assertEquals($g->modifiedAt, $changed->modifiedAt);
    }

    /** @return iterable<string, array{int, int, ?int}> */
    public static function invalidGalleries(): iterable
    {
        yield 'zero id' => [0, 1, null];
        yield 'negative owner' => [1, -1, null];
        yield 'zero cover' => [1, 1, 0];
    }

    #[DataProvider('invalidGalleries')]
    public function testGalleryRejectsInvalidIdentifiers(int $id, int $owner, ?int $cover): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Gallery($id, 'T', '', GalleryStatus::Draft, $owner, $cover, new GallerySettings(), new \DateTimeImmutable());
    }

    public function testNewGalleryDefaultsToDraftAndRejectsTrash(): void
    {
        $new = new NewGallery('T', 1);
        self::assertSame(GalleryStatus::Draft, $new->status);
        self::assertSame('grid', $new->settings->layout);

        $this->expectException(\InvalidArgumentException::class);
        new NewGallery('T', 1, '', GalleryStatus::Trash);
    }

    public function testNewGalleryRejectsNegativeOwner(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NewGallery('T', -1);
    }

    public function testSettingsKeepUnknownKeysAcrossWithers(): void
    {
        $s = new GallerySettings('grid', SortMode::Manual, ['x' => 1]);
        $next = $s->withLayout('masonry')->withSortMode(SortMode::TitleAsc);

        self::assertSame(['x' => 1], $next->values);
        self::assertSame(SortMode::TitleAsc, $next->sortMode);
        self::assertSame(['y' => 2], $next->withValues(['y' => 2])->values);
        self::assertSame(GallerySettings::SCHEMA_VERSION, $next->schemaVersion);
    }

    public function testPerPageDefaultsToNullIsKeptByWithersAndRangeChecked(): void
    {
        $s = new GallerySettings();
        self::assertNull($s->perPage);

        $set = $s->withPerPage(12);
        self::assertSame(12, $set->perPage);
        self::assertSame(12, $set->withLayout('masonry')->withSortMode(SortMode::TitleAsc)->withValues(['a' => 1])->perPage);
        self::assertNull($set->withPerPage(null)->perPage);
        self::assertSame(GallerySettings::MAX_PER_PAGE, $s->withPerPage(GallerySettings::MAX_PER_PAGE)->perPage);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidPerPage(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'too large' => [GallerySettings::MAX_PER_PAGE + 1];
    }

    #[DataProvider('invalidPerPage')]
    public function testPerPageOutOfRangeIsRejected(int $perPage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GallerySettings(perPage: $perPage);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidSettings(): iterable
    {
        yield 'empty layout' => ['', 1];
        yield 'uppercase layout' => ['Grid', 1];
        yield 'path-like layout' => ['../x', 1];
        yield 'too long layout' => [str_repeat('a', 65), 1];
        yield 'schema version zero' => ['grid', 0];
    }

    #[DataProvider('invalidSettings')]
    public function testSettingsRejectInvalidInput(string $layout, int $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GallerySettings($layout, SortMode::Manual, [], $version);
    }

    /** @return iterable<string, array{int, int, ?int}> */
    public static function invalidQueries(): iterable
    {
        yield 'negative offset' => [-1, 10, null];
        yield 'zero limit' => [0, 0, null];
        yield 'limit above maximum' => [0, GalleryQuery::MAX_LIMIT + 1, null];
        yield 'negative owner' => [0, 10, -1];
    }

    #[DataProvider('invalidQueries')]
    public function testQueryIsBounded(int $offset, int $limit, ?int $owner): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GalleryQuery(null, [], $owner, $offset, $limit);
    }

    public function testQueryAcceptsTheMaximum(): void
    {
        self::assertSame(100, (new GalleryQuery(limit: GalleryQuery::MAX_LIMIT))->limit);
    }

    public function testPageTotalCannotBeBelowItemCount(): void
    {
        self::assertSame(1, (new GalleryPage([$this->gallery()], 1))->total);
        $this->expectException(\InvalidArgumentException::class);
        new GalleryPage([$this->gallery()], 0);
    }
}
