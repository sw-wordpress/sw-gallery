<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/** Publication state of a gallery. Values mirror the WordPress post statuses the adapter maps to. */
enum GalleryStatus: string
{
    case Publish = 'publish';
    case Private = 'private';
    case Draft = 'draft';
    case Pending = 'pending';
    case Future = 'future';
    case Trash = 'trash';
}
