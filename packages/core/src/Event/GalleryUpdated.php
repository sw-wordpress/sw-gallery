<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/** The gallery definition or settings were saved. */
final class GalleryUpdated extends AbstractGalleryEvent
{
    public function name(): string
    {
        return 'gallery_updated';
    }
}
