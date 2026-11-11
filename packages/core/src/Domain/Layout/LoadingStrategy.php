<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout;

/** Loading behaviour of one thumbnail (M3-06). */
enum LoadingStrategy: string
{
    case Lazy = 'lazy';
    /** Part of the first row: loaded immediately. */
    case Eager = 'eager';
    /** The likely LCP image: eager and `fetchpriority="high"`. */
    case Priority = 'priority';
}
