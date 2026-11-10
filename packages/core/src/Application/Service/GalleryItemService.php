<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Service;

use SWGallery\Core\Contract\GalleryItemRepositoryInterface;
use SWGallery\Core\Contract\MediaRepositoryInterface;
use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Contract\TransactionRunnerInterface;
use SWGallery\Core\Domain\Gallery\AssignResult;
use SWGallery\Core\Domain\Gallery\RemoveResult;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Event\EventDispatcherInterface;
use SWGallery\Core\Event\ItemsAssigned;
use SWGallery\Core\Event\ItemsRemoved;
use SWGallery\Core\Event\ItemsReordered;
use SWGallery\Core\Exception\InvalidOrderException;
use SWGallery\Core\Exception\ItemNotFoundException;

/**
 * Membership use cases. Every persisted change runs in one transaction that claims the next revision first
 * (compare-and-increment), so a stale client fails with a conflict before anything is written and a failing
 * write rolls the revision back too. Events are dispatched only after the transaction succeeded.
 */
final class GalleryItemService
{
    public const MAX_BATCH = 500;

    public function __construct(
        private readonly GalleryItemRepositoryInterface $items,
        private readonly RevisionStoreInterface $revisions,
        private readonly MediaRepositoryInterface $media,
        private readonly TransactionRunnerInterface $transactions,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * @param list<int> $attachmentIds
     */
    public function assign(int $galleryId, array $attachmentIds, Revision $expected): AssignResult
    {
        $ids = $this->sanitizeIds($attachmentIds);

        $result = $this->transactions->run(function () use ($galleryId, $ids, $expected): AssignResult {
            $existing = $this->media->existingImageIds($ids);
            $missing = array_values(array_diff($ids, $existing));
            $duplicates = $this->items->assignedAttachmentIds($galleryId, $existing);
            $toAdd = array_values(array_diff($existing, $duplicates));

            if ($toAdd === []) {
                return new AssignResult([], $duplicates, $missing, $this->revisions->current($galleryId));
            }

            $revision = $this->revisions->compareAndIncrement($galleryId, $expected);
            foreach ($toAdd as $attachmentId) {
                $this->items->append($galleryId, $attachmentId);
            }

            return new AssignResult($toAdd, $duplicates, $missing, $revision);
        });

        if ($result->added !== []) {
            $this->events->dispatch(new ItemsAssigned($galleryId, $result->added, $result->revision->value));
        }

        return $result;
    }

    /**
     * @param list<int> $itemIds Complete ordered list of the gallery's item IDs.
     */
    public function reorder(int $galleryId, array $itemIds, Revision $expected): Revision
    {
        $revision = $this->transactions->run(function () use ($galleryId, $itemIds, $expected): Revision {
            $current = $this->items->orderedIds($galleryId);
            if (
                count($itemIds) !== count($current)
                || count(array_unique($itemIds)) !== count($itemIds)
                || array_diff($itemIds, $current) !== []
            ) {
                throw new InvalidOrderException();
            }

            $revision = $this->revisions->compareAndIncrement($galleryId, $expected);
            $this->items->applyOrder($galleryId, $itemIds);

            return $revision;
        });

        $this->events->dispatch(new ItemsReordered($galleryId, $revision->value));

        return $revision;
    }

    public function remove(int $galleryId, int $itemId, Revision $expected): Revision
    {
        $revision = $this->transactions->run(function () use ($galleryId, $itemId, $expected): Revision {
            if (!in_array($itemId, $this->items->orderedIds($galleryId), true)) {
                throw new ItemNotFoundException();
            }

            $revision = $this->revisions->compareAndIncrement($galleryId, $expected);
            if (!$this->items->remove($galleryId, $itemId)) {
                throw new ItemNotFoundException();
            }

            return $revision;
        });

        $this->events->dispatch(new ItemsRemoved($galleryId, [$itemId], $revision->value));

        return $revision;
    }

    /**
     * Removes up to MAX_BATCH relations in one atomic step. Unknown item IDs are reported, not fatal; when
     * nothing could be removed the revision stays unchanged.
     *
     * @param list<int> $itemIds
     * @throws \InvalidArgumentException When the batch exceeds MAX_BATCH.
     */
    public function removeMany(int $galleryId, array $itemIds, Revision $expected): RemoveResult
    {
        $ids = $this->sanitizeIds($itemIds);

        $result = $this->transactions->run(function () use ($galleryId, $ids, $expected): RemoveResult {
            $present = array_values(array_intersect($ids, $this->items->orderedIds($galleryId)));
            $notFound = array_values(array_diff($ids, $present));
            if ($present === []) {
                return new RemoveResult([], $notFound, $this->revisions->current($galleryId));
            }

            $revision = $this->revisions->compareAndIncrement($galleryId, $expected);
            $removed = [];
            foreach ($present as $itemId) {
                if ($this->items->remove($galleryId, $itemId)) {
                    $removed[] = $itemId;
                } else {
                    $notFound[] = $itemId;
                }
            }

            return new RemoveResult($removed, $notFound, $revision);
        });

        if ($result->removed !== []) {
            $this->events->dispatch(new ItemsRemoved($galleryId, $result->removed, $result->revision->value));
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sanitizeIds(array $ids): array
    {
        $clean = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if (count($clean) > self::MAX_BATCH) {
            throw new \InvalidArgumentException('Too many IDs in one request.');
        }

        return $clean;
    }
}
