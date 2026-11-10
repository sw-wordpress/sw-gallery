<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Gallery\GalleryItem;

/** Persistence of gallery membership. Removing an item only ever removes the relation. */
interface GalleryItemRepositoryInterface
{
    /**
     * @return list<GalleryItem> One page ordered by position, then id.
     */
    public function findPage(int $galleryId, int $offset, int $limit): array;

    public function count(int $galleryId): int;

    /**
     * @return list<int> All item IDs in display order (IDs only, never full rows).
     */
    public function orderedIds(int $galleryId): array;

    /**
     * @param list<int> $attachmentIds
     * @return list<int> The subset of attachment IDs already in the gallery.
     */
    public function assignedAttachmentIds(int $galleryId, array $attachmentIds): array;

    /** Appends an item at the end. Uniqueness (gallery, attachment) is enforced by the store. */
    public function append(int $galleryId, int $attachmentId): GalleryItem;

    /** @return bool Whether a relation was removed. */
    public function remove(int $galleryId, int $itemId): bool;

    /**
     * Writes positions 1..n for the given item IDs in one atomic operation.
     *
     * @param list<int> $itemIds
     */
    public function applyOrder(int $galleryId, array $itemIds): void;
}
