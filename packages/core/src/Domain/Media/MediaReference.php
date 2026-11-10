<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Media;

/** Read-only snapshot of the global metadata of one attachment (never mutated by galleries). */
final class MediaReference
{
    public function __construct(
        public readonly int $attachmentId,
        public readonly string $title,
        public readonly string $caption,
        public readonly string $alt,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height,
    ) {
        if ($attachmentId <= 0 || $width < 0 || $height < 0) {
            throw new \InvalidArgumentException('Invalid media reference.');
        }
    }

    public function hasDimensions(): bool
    {
        return $this->width > 0 && $this->height > 0;
    }

    /** Width divided by height, or null when the attachment has no usable dimensions. */
    public function aspectRatio(): ?float
    {
        return $this->hasDimensions() ? $this->width / $this->height : null;
    }
}
