<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/** Item relations were removed from a gallery; the attachments themselves are untouched. */
final class ItemsRemoved extends AbstractGalleryEvent
{
    /**
     * @param list<int> $itemIds Items that were actually removed.
     */
    public function __construct(int $galleryId, public readonly array $itemIds, int $revision)
    {
        parent::__construct($galleryId, $revision);
    }

    public function name(): string
    {
        return 'items_removed';
    }

    public function payload(): array
    {
        return ['gallery_id' => $this->galleryId, 'item_ids' => $this->itemIds, 'revision' => $this->revision];
    }
}
