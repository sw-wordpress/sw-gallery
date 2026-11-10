<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/** The overrides or the settings of one gallery item changed. */
final class ItemUpdated extends AbstractGalleryEvent
{
    public function __construct(int $galleryId, public readonly int $itemId, int $revision)
    {
        parent::__construct($galleryId, $revision);
    }

    public function name(): string
    {
        return 'item_updated';
    }

    public function payload(): array
    {
        return ['gallery_id' => $this->galleryId, 'item_id' => $this->itemId, 'revision' => $this->revision];
    }
}
