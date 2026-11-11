<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Layout\ImageHints;

/**
 * Optional capability of a layout (additive to GalleryLayoutInterface): tells the pipeline how large its thumbnails
 * are displayed. Layouts without it get ImageHints::fallback().
 */
interface ImageHintsProviderInterface
{
    public function imageHints(GallerySettings $settings): ImageHints;
}
