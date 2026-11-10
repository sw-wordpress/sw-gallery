<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/** The gallery was moved to the trash; item relations are kept. */
final class GalleryTrashed extends AbstractGalleryEvent
{
    public function name(): string
    {
        return 'gallery_trashed';
    }
}
