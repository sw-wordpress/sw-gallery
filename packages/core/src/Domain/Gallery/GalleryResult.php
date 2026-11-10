<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/** A gallery together with the revision its last change produced. */
final class GalleryResult
{
    public function __construct(
        public readonly Gallery $gallery,
        public readonly Revision $revision,
    ) {
    }
}
