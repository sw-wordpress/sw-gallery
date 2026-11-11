<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Render;

use SWGallery\Core\Domain\Gallery\GalleryItem;

/** One page of usable items in display order plus the number of usable items in the whole gallery. */
final class RenderItemPage
{
    /**
     * @param list<GalleryItem> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
    ) {
        if ($total < 0) {
            throw new \InvalidArgumentException('Invalid item total.');
        }
    }
}
