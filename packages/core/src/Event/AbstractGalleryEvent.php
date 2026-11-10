<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/** Base of events about one gallery and the revision its change produced. */
abstract class AbstractGalleryEvent implements EventInterface
{
    public function __construct(
        public readonly int $galleryId,
        public readonly int $revision,
    ) {
    }

    public function payload(): array
    {
        return ['gallery_id' => $this->galleryId, 'revision' => $this->revision];
    }
}
