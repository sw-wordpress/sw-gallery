<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout;

use SWGallery\Core\Contract\GalleryLayoutInterface;

/** Result of resolving a layout id; `fellBack` lets callers show the editor-only diagnostic. */
final class LayoutResolution
{
    public function __construct(
        public readonly GalleryLayoutInterface $layout,
        public readonly string $requestedId,
        public readonly bool $fellBack,
    ) {
    }
}
