<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout;

/** What a layout needs from an image: the attachment and the width it is displayed at (CSS pixels). */
final class ImageRequest
{
    public function __construct(
        public readonly int $attachmentId,
        public readonly int $displayWidth,
        public readonly string $sizes = '100vw',
    ) {
        if ($attachmentId <= 0 || $displayWidth <= 0 || $sizes === '') {
            throw new \InvalidArgumentException('Invalid image request.');
        }
    }
}
