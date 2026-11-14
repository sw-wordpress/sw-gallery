<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/** A reference of one WordPress attachment inside one gallery. It never owns the file. */
final class GalleryItem
{
    public function __construct(
        public readonly int $id,
        public readonly int $galleryId,
        public readonly int $attachmentId,
        public readonly int $position,
        public readonly ItemOverrides $overrides = new ItemOverrides(),
        /**
         * Layout settings of the item; empty unless the source loaded them (the renderer's source does).
         *
         * @var array<string, mixed>
         */
        public readonly array $settings = [],
    ) {
        if ($id < 0 || $galleryId <= 0 || $attachmentId <= 0 || $position < 0) {
            throw new \InvalidArgumentException('Invalid gallery item identifiers.');
        }
    }
}
