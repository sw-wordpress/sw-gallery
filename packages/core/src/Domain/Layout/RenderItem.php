<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout;

use SWGallery\Core\Domain\Gallery\HoverEffect;

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
        /** 1-based position among ALL items of the gallery (not of the page); 0 when unknown. */
        public readonly int $position = 0,
        /** Number of items of the gallery; 0 when unknown. */
        public readonly int $total = 0,
        /**
         * Stored per-item layout settings (the item's `settings` map; layouts clamp what they read). Empty when none.
         *
         * @var array<string, mixed>
         */
        public readonly array $settings = [],
        /** Effective hover texts of the gallery (identical for every item); null or inactive renders nothing. */
        public readonly ?HoverEffect $hover = null,
    ) {
        if ($itemId <= 0 || $attachmentId <= 0 || $position < 0 || $total < 0) {
            throw new \InvalidArgumentException('Invalid render item.');
        }
    }
}
