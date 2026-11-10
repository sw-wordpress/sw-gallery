<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Service;

use SWGallery\Core\Contract\GalleryItemRepositoryInterface;
use SWGallery\Core\Contract\MediaRepositoryInterface;
use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Domain\Gallery\AssignResult;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Exception\InvalidOrderException;
use SWGallery\Core\Exception\ItemNotFoundException;

/**
 * Membership use cases. Every persisted change claims the next revision first (compare-and-increment),
 * so a stale client fails with a conflict before anything is written.
 */
final class GalleryItemService
{
    public const MAX_BATCH = 500;

    public function __construct(
        private readonly GalleryItemRepositoryInterface $items,
        private readonly RevisionStoreInterface $revisions,
        private readonly MediaRepositoryInterface $media,
    ) {
    }

    /**
     * @param list<int> $attachmentIds
     */
    public function assign(int $galleryId, array $attachmentIds, Revision $expected): AssignResult
    {
        $ids = array_values(array_unique(array_filter($attachmentIds, static fn (int $id): bool => $id > 0)));
        if (count($ids) > self::MAX_BATCH) {
            throw new \InvalidArgumentException('Too many attachments in one request.');
        }

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
    }

    /**
     * @param list<int> $itemIds Complete ordered list of the gallery's item IDs.
     */
    public function reorder(int $galleryId, array $itemIds, Revision $expected): Revision
    {
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
    }

    public function remove(int $galleryId, int $itemId, Revision $expected): Revision
    {
        if (!in_array($itemId, $this->items->orderedIds($galleryId), true)) {
            throw new ItemNotFoundException();
        }

        $revision = $this->revisions->compareAndIncrement($galleryId, $expected);
        $this->items->remove($galleryId, $itemId);

        return $revision;
    }
}
