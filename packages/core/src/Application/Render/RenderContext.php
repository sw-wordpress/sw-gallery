<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Render;

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\HoverEffect;

/**
 * Everything one render call needs to know about its caller. Overrides are untrusted embed attributes: the pipeline
 * validates them, and they never change the stored gallery.
 */
final class RenderContext
{
    public const DEFAULT_PER_PAGE = 60;
    public const MAX_PER_PAGE = 200;

    /**
     * @param array<string, mixed> $settingsOverrides Layout settings that replace the gallery's values for this embed.
     * @param bool                 $eagerLoading      Per-embed switch: false lazy-loads every image.
     * @param bool                 $highPriority      Whether the first image may carry fetchpriority="high"
     *                                                (the adapter clears it for every embed after the first on a page).
     * @param HoverEffect|null     $hover             Effective hover texts (gallery setting or site default); only
     *                                                interactive entry points render them.
     */
    public function __construct(
        public readonly int $galleryId,
        public readonly EntryPoint $entryPoint,
        public readonly Actor $actor,
        public readonly string $instanceId,
        public readonly int $page = 1,
        public readonly int $perPage = self::DEFAULT_PER_PAGE,
        public readonly ?string $layoutOverride = null,
        public readonly array $settingsOverrides = [],
        public readonly bool $passwordUnlocked = false,
        public readonly bool $eagerLoading = true,
        public readonly bool $highPriority = true,
        public readonly ?HoverEffect $hover = null,
    ) {
        if ($galleryId <= 0 || $page < 1 || $perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new \InvalidArgumentException('Invalid render context.');
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $instanceId) !== 1) {
            throw new \InvalidArgumentException('Invalid instance id.');
        }
        // Embed attributes are untrusted: an invalid id is rejected here, never repaired.
        if ($layoutOverride !== null && preg_match(GallerySettings::LAYOUT_ID_PATTERN, $layoutOverride) !== 1) {
            throw new \InvalidArgumentException('Invalid layout override.');
        }
    }
}
