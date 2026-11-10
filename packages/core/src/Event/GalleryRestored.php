<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/** The gallery was restored from the trash as a draft. */
final class GalleryRestored extends AbstractGalleryEvent
{
    public function name(): string
    {
        return 'gallery_restored';
    }
}
