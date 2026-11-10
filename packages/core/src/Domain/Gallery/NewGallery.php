<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/** Input for creating a gallery. New galleries start as drafts unless stated otherwise. */
final class NewGallery
{
    public function __construct(
        public readonly string $title,
        public readonly int $ownerId,
        public readonly string $description = '',
        public readonly GalleryStatus $status = GalleryStatus::Draft,
        public readonly GallerySettings $settings = new GallerySettings(),
    ) {
        if ($ownerId < 0) {
            throw new \InvalidArgumentException('Invalid owner id.');
        }
        if ($status === GalleryStatus::Trash) {
            throw new \InvalidArgumentException('A gallery cannot be created in the trash.');
        }
    }
}
