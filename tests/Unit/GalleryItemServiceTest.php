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
use SWGallery\Core\Event\EventDispatcher;
use SWGallery\Core\Event\EventInterface;
use SWGallery\Core\Logging\NullLogger;
use SWGallery\Tests\Support\InMemoryItems;
use SWGallery\Tests\Support\InMemoryTransactionRunner;
use SWGallery\Tests\Support\VanishingItems;

final class GalleryItemServiceTest extends TestCase
{
    private InMemoryItems $store;
    private GalleryItemService $service;
    private InMemoryTransactionRunner $runner;
    private EventDispatcher $events;
    /** @var list<string> */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->store = new InMemoryItems([10, 11, 12]);
        $this->runner = new InMemoryTransactionRunner([$this->store]);
        $this->events = new EventDispatcher(new NullLogger());
        $this->events->listen(EventInterface::class, function (EventInterface $e): void {
            $this->seen[] = $e->name();
        });
        $this->service = $this->serviceWith($this->runner);
    }

    private function serviceWith(InMemoryTransactionRunner $runner): GalleryItemService
    {
        return new GalleryItemService($this->store, $this->store, $this->store, $runner, $this->events);
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

    public function testEventsAreDispatchedOnlyForRealChanges(): void
    {
        $this->service->assign(1, [10, 11], new Revision(0));
        $this->service->assign(1, [10], new Revision(1));
        $ids = $this->store->orderedIds(1);
        $this->service->reorder(1, array_reverse($ids), new Revision(1));
        $this->service->remove(1, $ids[0], new Revision(2));
        $this->service->removeMany(1, [999], new Revision(3));
        $this->service->removeMany(1, [$ids[1]], new Revision(3));

        self::assertSame(['items_assigned', 'items_reordered', 'items_removed', 'items_removed'], $this->seen);
    }

    public function testFailingWriteRollsBackItemsAndRevisionAndDispatchesNothing(): void
    {
        $this->store->failAfterWrites(1);

        try {
            $this->service->assign(1, [10, 11, 12], new Revision(0));
            self::fail('The simulated failure must surface.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->store->count(1));
            self::assertSame(0, $this->store->current(1)->value);
            self::assertSame([], $this->seen);
            self::assertSame(1, $this->runner->rollbacks);
        }
    }

    public function testFailingRemoveAndReorderRollBackToo(): void
    {
        $this->service->assign(1, [10, 11], new Revision(0));
        $ids = $this->store->orderedIds(1);

        $this->store->failAfterWrites(0);
        foreach (
            [
                fn () => $this->service->reorder(1, array_reverse($ids), new Revision(1)),
                fn () => $this->service->remove(1, $ids[0], new Revision(1)),
                fn () => $this->service->removeMany(1, $ids, new Revision(1)),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('The simulated failure must surface.');
            } catch (\RuntimeException) {
                self::assertSame(1, $this->store->current(1)->value);
                self::assertSame($ids, $this->store->orderedIds(1));
            }
        }
    }

    public function testWithoutTransactionsTheRevisionIsStillClaimedBeforeTheFailingWrite(): void
    {
        $service = $this->serviceWith(new InMemoryTransactionRunner([$this->store], false));
        $this->store->failAfterWrites(1);

        try {
            $service->assign(1, [10, 11], new Revision(0));
            self::fail('The simulated failure must surface.');
        } catch (\RuntimeException) {
            self::assertSame(1, $this->store->count(1), 'partial state remains on engines without transactions');
            self::assertSame(1, $this->store->current(1)->value, 'the revision moved: stale clients reload');
        }
    }

    public function testRemoveManyIsHonestAndBounded(): void
    {
        $this->service->assign(1, [10, 11, 12], new Revision(0));
        $ids = $this->store->orderedIds(1);

        $result = $this->service->removeMany(1, [$ids[0], $ids[2], $ids[0], 777, -1], new Revision(1));

        self::assertSame([$ids[0], $ids[2]], $result->removed);
        self::assertSame([777], $result->notFound);
        self::assertSame(2, $result->revision->value);
        self::assertSame([$ids[1]], $this->store->orderedIds(1));
        self::assertSame([10, 11, 12], $this->store->existingImageIds([10, 11, 12]));
    }

    public function testRemoveManyWithNothingToRemoveKeepsTheRevision(): void
    {
        $result = $this->service->removeMany(1, [5], new Revision(0));

        self::assertSame([], $result->removed);
        self::assertSame([5], $result->notFound);
        self::assertSame(0, $result->revision->value);
    }

    public function testRemoveManyChecksTheRevisionAndTheBatchLimit(): void
    {
        $this->service->assign(1, [10], new Revision(0));
        $id = $this->store->orderedIds(1)[0];

        try {
            $this->service->removeMany(1, [$id], new Revision(0));
            self::fail('A stale revision must conflict.');
        } catch (RevisionConflictException) {
            self::assertSame(1, $this->store->count(1));
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->service->removeMany(1, range(1, GalleryItemService::MAX_BATCH + 1), new Revision(1));
    }

    public function testConcurrentlyVanishedRelationIsReportedHonestly(): void
    {
        $this->service->assign(1, [10, 11], new Revision(0));
        $ids = $this->store->orderedIds(1);
        $service = new GalleryItemService(
            new VanishingItems($this->store),
            $this->store,
            $this->store,
            $this->runner,
            $this->events
        );

        $result = $service->removeMany(1, [$ids[0]], new Revision(1));
        self::assertSame([], $result->removed);
        self::assertSame([$ids[0]], $result->notFound);

        try {
            $service->remove(1, $ids[0], new Revision(2));
            self::fail('A vanished relation must raise not found.');
        } catch (ItemNotFoundException) {
            self::assertSame(2, $this->store->current(1)->value, 'the failed removal rolled its claim back');
        }
    }

    public function testErrorCodesAreStable(): void
    {
        self::assertSame('swg_revision_conflict', (new RevisionConflictException(1, 2))->errorCode());
        self::assertSame('swg_invalid_order', (new InvalidOrderException())->errorCode());
        self::assertSame('swg_item_not_found', (new ItemNotFoundException())->errorCode());
    }
}
