<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use PHPUnit\Framework\TestCase;
use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Domain\Gallery\GalleryQuery;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Exception\GalleryNotFoundException;

/**
 * Behaviour every GalleryRepositoryInterface implementation must show. Extend it in the unit suite (in-memory fake)
 * and in the WordPress integration suite (real adapter) and implement the two factory methods.
 */
abstract class GalleryRepositoryContract extends TestCase
{
    abstract protected function repository(): GalleryRepositoryInterface;

    /** Puts the given attachments into the gallery's membership. */
    /** @param list<int> $attachmentIds */
    abstract protected function seedItems(int $galleryId, array $attachmentIds): void;

    abstract protected function itemCount(int $galleryId): int;

    public function testCreateThenFindRoundTrips(): void
    {
        $repo = $this->repository();
        $settings = new GallerySettings('masonry', SortMode::DateDesc, ['columns' => 4, 'future_key' => 'kept']);
        $created = $repo->create(new NewGallery('Summer', 7, "Line 1\nLine 2", GalleryStatus::Draft, $settings));

        $found = $repo->find($created->id);
        self::assertNotNull($found);
        self::assertSame('Summer', $found->title);
        self::assertSame("Line 1\nLine 2", $found->description);
        self::assertSame(GalleryStatus::Draft, $found->status);
        self::assertSame(7, $found->ownerId);
        self::assertNull($found->coverAttachmentId);
        self::assertSame('masonry', $found->settings->layout);
        self::assertSame(SortMode::DateDesc, $found->settings->sortMode);
        self::assertSame(['columns' => 4, 'future_key' => 'kept'], $found->settings->values);
    }

    public function testFindUnknownReturnsNull(): void
    {
        self::assertNull($this->repository()->find(999999));
    }

    public function testUpdatePersistsEditableFields(): void
    {
        $repo = $this->repository();
        $gallery = $repo->create(new NewGallery('Old', 1));
        $repo->update(
            $gallery->withTitle('New')->withDescription('Text')->withStatus(GalleryStatus::Publish)
                ->withOwner(2)->withCover(55)->withSettings(new GallerySettings('justified'))
        );

        $found = $repo->find($gallery->id);
        self::assertNotNull($found);
        self::assertSame('New', $found->title);
        self::assertSame('Text', $found->description);
        self::assertSame(GalleryStatus::Publish, $found->status);
        self::assertSame(2, $found->ownerId);
        self::assertSame(55, $found->coverAttachmentId);
        self::assertSame('justified', $found->settings->layout);
    }

    public function testUpdateCanClearTheCover(): void
    {
        $repo = $this->repository();
        $gallery = $repo->create(new NewGallery('G', 1));
        $repo->update($gallery->withCover(5));
        $repo->update($gallery->withCover(null));

        self::assertNull($repo->find($gallery->id)?->coverAttachmentId);
    }

    public function testUpdateRejectsTrashStatus(): void
    {
        $repo = $this->repository();
        $gallery = $repo->create(new NewGallery('G', 1));

        try {
            $repo->update($gallery->withStatus(GalleryStatus::Trash));
            self::fail('Trash through update() must be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame(GalleryStatus::Draft, $repo->find($gallery->id)?->status);
        }
    }

    public function testUpdateOfDeletedGalleryRaisesNotFound(): void
    {
        $repo = $this->repository();
        $gallery = $repo->create(new NewGallery('G', 1));
        $repo->delete($gallery->id);

        $this->expectException(GalleryNotFoundException::class);
        $repo->update($gallery);
    }

    public function testSearchFiltersPagesAndExcludesTrashByDefault(): void
    {
        $repo = $this->repository();
        $a = $repo->create(new NewGallery('Alpine trip', 1, 'Mountains', GalleryStatus::Publish));
        $b = $repo->create(new NewGallery('Beach', 2, 'Sea and sun', GalleryStatus::Draft));
        $c = $repo->create(new NewGallery('Alpine club', 1, '', GalleryStatus::Publish));
        $repo->trash($c->id);

        self::assertSame(2, $repo->search(new GalleryQuery())->total);
        self::assertSame([$a->id], $this->ids($repo->search(new GalleryQuery('alpine'))->items));
        self::assertSame([$b->id], $this->ids($repo->search(new GalleryQuery('SUN'))->items));
        self::assertSame([$b->id], $this->ids($repo->search(new GalleryQuery(ownerId: 2))->items));
        self::assertSame([$c->id], $this->ids($repo->search(new GalleryQuery(statuses: [GalleryStatus::Trash]))->items));

        $page = $repo->search(new GalleryQuery(offset: 1, limit: 1));
        self::assertCount(1, $page->items);
        self::assertSame(2, $page->total);
    }

    public function testTrashKeepsRelationsAndRestoreReturnsDraft(): void
    {
        $repo = $this->repository();
        $gallery = $repo->create(new NewGallery('G', 1, '', GalleryStatus::Publish));
        $this->seedItems($gallery->id, [10, 11]);

        $repo->trash($gallery->id);
        self::assertSame(GalleryStatus::Trash, $repo->find($gallery->id)?->status);
        self::assertSame(2, $this->itemCount($gallery->id));

        $restored = $repo->restore($gallery->id);
        self::assertSame(GalleryStatus::Draft, $restored->status);
        self::assertSame(2, $this->itemCount($gallery->id));
    }

    public function testDeleteRemovesGalleryAndRelations(): void
    {
        $repo = $this->repository();
        $gallery = $repo->create(new NewGallery('G', 1));
        $this->seedItems($gallery->id, [10]);

        $repo->delete($gallery->id);

        self::assertNull($repo->find($gallery->id));
        self::assertSame(0, $this->itemCount($gallery->id));
    }

    public function testDuplicateCopiesDefinitionAndMembershipAsDraftOfNewOwner(): void
    {
        $repo = $this->repository();
        $source = $repo->create(new NewGallery('Source', 1, 'Desc', GalleryStatus::Publish, new GallerySettings('masonry')));
        $repo->update($source->withCover(77));
        $this->seedItems($source->id, [10, 11, 12]);

        $copy = $repo->duplicate($source->id, 'Copy', 9);

        self::assertNotSame($source->id, $copy->id);
        self::assertSame('Copy', $copy->title);
        self::assertSame('Desc', $copy->description);
        self::assertSame(GalleryStatus::Draft, $copy->status);
        self::assertSame(9, $copy->ownerId);
        self::assertSame(77, $copy->coverAttachmentId);
        self::assertSame('masonry', $copy->settings->layout);
        self::assertSame(3, $this->itemCount($copy->id));
        self::assertSame(3, $this->itemCount($source->id));
    }

    public function testMissingGalleryRaisesNotFound(): void
    {
        $repo = $this->repository();
        foreach (
            [
                static fn () => $repo->duplicate(999999, 'x', 1),
                static fn () => $repo->trash(999999),
                static fn () => $repo->restore(999999),
                static fn () => $repo->delete(999999),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Expected GalleryNotFoundException.');
            } catch (GalleryNotFoundException $e) {
                self::assertSame('swg_gallery_not_found', $e->errorCode());
            }
        }
    }

    /**
     * @param list<\SWGallery\Core\Domain\Gallery\Gallery> $galleries
     * @return list<int>
     */
    private function ids(array $galleries): array
    {
        return array_map(static fn ($g): int => $g->id, $galleries);
    }
}
