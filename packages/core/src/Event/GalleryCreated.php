<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/** A gallery was created (revision 1). */
final class GalleryCreated extends AbstractGalleryEvent
{
    public function __construct(int $galleryId, public readonly int $ownerId, int $revision)
    {
        parent::__construct($galleryId, $revision);
    }

    public function name(): string
    {
        return 'gallery_created';
    }

    public function payload(): array
    {
        return ['gallery_id' => $this->galleryId, 'owner_id' => $this->ownerId, 'revision' => $this->revision];
    }
}
