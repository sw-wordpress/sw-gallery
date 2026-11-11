<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout;

/** How a layout displays its thumbnails: target width, `sizes` attribute and how many images form the first row. */
final class ImageHints
{
    public const MAX_FIRST_ROW = 6;

    public function __construct(
        public readonly int $displayWidth,
        public readonly string $sizes,
        public readonly int $firstRowCount = 3,
    ) {
        if ($displayWidth <= 0 || $sizes === '' || $firstRowCount < 1 || $firstRowCount > self::MAX_FIRST_ROW) {
            throw new \InvalidArgumentException('Invalid image hints.');
        }
    }

    public static function fallback(): self
    {
        return new self(480, '(min-width: 800px) 33vw, 100vw');
    }
}
