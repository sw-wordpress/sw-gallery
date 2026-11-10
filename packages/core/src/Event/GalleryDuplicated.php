<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/** A gallery was copied; `galleryId` is the new gallery. Media files are not copied. */
final class GalleryDuplicated extends AbstractGalleryEvent
{
    public function __construct(
        int $galleryId,
        public readonly int $sourceId,
        public readonly int $ownerId,
        int $revision,
    ) {
        parent::__construct($galleryId, $revision);
    }

    public function name(): string
    {
        return 'gallery_duplicated';
    }

    public function payload(): array
    {
        return [
            'gallery_id' => $this->galleryId,
            'source_id' => $this->sourceId,
            'owner_id' => $this->ownerId,
            'revision' => $this->revision,
        ];
    }
}
