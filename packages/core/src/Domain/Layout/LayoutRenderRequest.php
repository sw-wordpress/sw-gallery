<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Layout;

use SWGallery\Core\Domain\Gallery\GallerySettings;

/**
 * Input of one layout invocation: the items of the current page, in display order.
 * Refined together with the render pipeline (M3-01).
 */
final class LayoutRenderRequest
{
    public const INSTANCE_ID_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

    /**
     * @param list<RenderItem> $items
     * @param string           $instanceId Unique per gallery embed on a page; safe for DOM ids and CSS selectors.
     */
    public function __construct(
        public readonly int $galleryId,
        public readonly string $instanceId,
        public readonly array $items,
        public readonly GallerySettings $settings,
        /** Stored gallery revision (0 when unknown); with the gallery id it is the deterministic seed of plan layouts. */
        public readonly int $revision = 0,
    ) {
        if ($galleryId <= 0 || $revision < 0 || preg_match(self::INSTANCE_ID_PATTERN, $instanceId) !== 1) {
            throw new \InvalidArgumentException('Invalid layout render request.');
        }
    }
}
