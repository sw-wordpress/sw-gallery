<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SWGallery\Core\Application\Service\GalleryItemService;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Exception\InvalidOrderException;
use SWGallery\Core\Exception\ItemNotFoundException;
use SWGallery\Core\Exception\RevisionConflictException;
use SWGallery\Tests\Support\InMemoryItems;

final class GalleryItemServiceTest extends TestCase
{
    private InMemoryItems $store;
    private GalleryItemService $service;

    protected function setUp(): void
    {
        $this->store = new InMemoryItems([10, 11, 12]);
        $this->service = new GalleryItemService($this->store, $this->store, $this->store);
    }

    public function testAssignReportsAddedDuplicatesAndMissingHonestly(): void
    {
        $first = $this->service->assign(1, [10, 11], new Revision(0));
        self::assertSame([10, 11], $first->added);
        self::assertSame(1, $first->revision->value);

        $second = $this->service->assign(1, [11, 12, 99, 12], new Revision(1));
        self::assertSame([12], $second->added);
        self::assertSame([11], $second->alreadyAssigned);
        self::assertSame([99], $second->missing);
        self::assertSame(3, $this->store->count(1));
    }

    public function testNoChangeKeepsRevision(): void
    {
        $this->service->assign(1, [10], new Revision(0));
        $result = $this->service->assign(1, [10], new Revision(1));
        self::assertSame([], $result->added);
        self::assertSame(1, $result->revision->value);
    }

    public function testSameAttachmentMayBelongToMultipleGalleries(): void
    {
        $this->service->assign(1, [10], new Revision(0));
        $this->service->assign(2, [10], new Revision(0));
        self::assertSame(1, $this->store->count(1));
        self::assertSame(1, $this->store->count(2));
    }

    public function testStaleRevisionWritesNothing(): void
    {
        $this->service->assign(1, [10], new Revision(0));
        $this->expectException(RevisionConflictException::class);
        try {
            $this->service->assign(1, [11], new Revision(0));
        } finally {
            self::assertSame(1, $this->store->count(1));
        }
    }

    public function testBatchLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->assign(1, range(1, GalleryItemService::MAX_BATCH + 1), new Revision(0));
    }

    public function testReorderAppliesCompleteOrderAndBumpsRevision(): void
    {
        $this->service->assign(1, [10, 11, 12], new Revision(0));
        $ids = $this->store->orderedIds(1);
        $reversed = array_reverse($ids);

        $revision = $this->service->reorder(1, $reversed, new Revision(1));

        self::assertSame(2, $revision->value);
        self::assertSame($reversed, $this->store->orderedIds(1));
    }

    /**
     * @param list<int> $order
     */
    #[DataProvider('invalidOrders')]
    public function testReorderRejectsAnythingButAPermutation(array $order): void
    {
        $this->service->assign(1, [10, 11], new Revision(0));
        $this->expectException(InvalidOrderException::class);
        $this->service->reorder(1, $order, new Revision(1));
    }

    /** @return array<string, array{list<int>}> */
    public static function invalidOrders(): array
    {
        return ['missing' => [[1]], 'duplicate' => [[1, 1]], 'foreign' => [[1, 99]], 'extra' => [[1, 2, 3]]];
    }

    public function testRemoveOnlyRemovesTheRelation(): void
    {
        $this->service->assign(1, [10, 11], new Revision(0));
        $itemId = $this->store->orderedIds(1)[0];

        $revision = $this->service->remove(1, $itemId, new Revision(1));

        self::assertSame(2, $revision->value);
        self::assertSame(1, $this->store->count(1));
        self::assertSame([10], $this->store->existingImageIds([10]), 'the attachment itself is untouched');
    }

    public function testRemoveUnknownItem(): void
    {
        $this->expectException(ItemNotFoundException::class);
        $this->service->remove(1, 42, new Revision(0));
    }

    public function testErrorCodesAreStable(): void
    {
        self::assertSame('swg_revision_conflict', (new RevisionConflictException(1, 2))->errorCode());
        self::assertSame('swg_invalid_order', (new InvalidOrderException())->errorCode());
        self::assertSame('swg_item_not_found', (new ItemNotFoundException())->errorCode());
    }
}
