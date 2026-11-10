<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/**
 * How a gallery orders its items (decision D3). Non-manual modes are resolved at query time;
 * the stored manual positions are never touched by them.
 */
enum SortMode: string
{
    case Manual = 'manual';
    case DateAsc = 'date_asc';
    case DateDesc = 'date_desc';
    case TitleAsc = 'title_asc';
    case TitleDesc = 'title_desc';
}
