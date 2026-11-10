<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout;

/** A concrete image variant chosen by the ThumbnailResolver. Dimensions are kept to prevent layout shift. */
final class ResolvedImage
{
    public function __construct(
        public readonly string $url,
        public readonly int $width,
        public readonly int $height,
        public readonly ?string $srcset = null,
        public readonly ?string $sizes = null,
    ) {
        if ($url === '' || $width < 0 || $height < 0) {
            throw new \InvalidArgumentException('Invalid resolved image.');
        }
    }
}
