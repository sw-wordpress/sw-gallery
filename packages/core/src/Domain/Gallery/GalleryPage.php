<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/** One page of a gallery search plus the total number of matches. */
final class GalleryPage
{
    /**
     * @param list<Gallery> $items
     */
    public function __construct(public readonly array $items, public readonly int $total)
    {
        if ($total < count($items)) {
            throw new \InvalidArgumentException('The total cannot be smaller than the page size.');
        }
    }
}
