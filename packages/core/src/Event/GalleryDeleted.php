<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/** The gallery and its item relations were permanently deleted; attachments are untouched. */
final class GalleryDeleted extends AbstractGalleryEvent
{
    public function name(): string
    {
        return 'gallery_deleted';
    }
}
