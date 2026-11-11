<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout;

/** What an image is used for; the resolver applies a different size policy to each. */
enum ImageRole: string
{
    case Thumbnail = 'thumbnail';
    /** Large image opened by the lightbox (or by the plain link without JavaScript). */
    case Lightbox = 'lightbox';
}
