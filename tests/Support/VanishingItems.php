<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\GalleryItemRepositoryInterface;
use SWGallery\Core\Domain\Gallery\GalleryItem;

/**
 * Item repository double that simulates a concurrent writer: the relation listed by orderedIds() is already gone
 * when remove() runs, so remove() reports false.
 */
final class VanishingItems implements GalleryItemRepositoryInterface
{
    public function __construct(private readonly InMemoryItems $inner)
    {
    }

    public function findPage(int $galleryId, int $offset, int $limit): array
    {
        return $this->inner->findPage($galleryId, $offset, $limit);
    }

    public function count(int $galleryId): int
    {
        return $this->inner->count($galleryId);
    }

    public function orderedIds(int $galleryId): array
    {
        return $this->inner->orderedIds($galleryId);
    }

    public function assignedAttachmentIds(int $galleryId, array $attachmentIds): array
    {
        return $this->inner->assignedAttachmentIds($galleryId, $attachmentIds);
    }

    public function append(int $galleryId, int $attachmentId): GalleryItem
    {
        return $this->inner->append($galleryId, $attachmentId);
    }

    public function remove(int $galleryId, int $itemId): bool
    {
        return false;
    }

    public function applyOrder(int $galleryId, array $itemIds): void
    {
        $this->inner->applyOrder($galleryId, $itemIds);
    }
}
