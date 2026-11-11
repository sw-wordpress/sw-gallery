<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout;

/**
 * One image of a page, already resolved: overrides applied (local over global) and variants chosen.
 * Texts are raw; layouts escape contextually when they build HTML.
 */
final class RenderItem
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $attachmentId,
        public readonly string $title,
        public readonly string $caption,
        public readonly string $alt,
        public readonly ResolvedImage $thumbnail,
        public readonly ?ResolvedImage $full = null,
        public readonly LoadingStrategy $loading = LoadingStrategy::Lazy,
    ) {
        if ($itemId <= 0 || $attachmentId <= 0) {
            throw new \InvalidArgumentException('Invalid render item.');
        }
    }
}
