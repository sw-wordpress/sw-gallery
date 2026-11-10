<?php

declare(strict_types=1);

namespace SWGallery\Core\Event;

/** The manual item order of a gallery changed. */
final class ItemsReordered extends AbstractGalleryEvent
{
    public function name(): string
    {
        return 'items_reordered';
    }
}
