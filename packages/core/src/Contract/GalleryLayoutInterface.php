<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Layout\LayoutRenderRequest;

/**
 * A gallery presentation strategy (grid, masonry, ...). Public extension point; the contract is versioned,
 * breaking changes need a new interface.
 */
interface GalleryLayoutInterface
{
    /** Stable id matching GallerySettings::LAYOUT_ID_PATTERN. */
    public function getId(): string;

    /** English label; translated at presentation time. */
    public function getLabel(): string;

    /**
     * Strict JSON-schema-style description of the layout's settings, including responsive values.
     *
     * @return array<string, mixed>
     */
    public function getSettingsSchema(): array;

    /**
     * Returns complete, contextually escaped HTML for the given page of items. Must stay usable without JavaScript.
     */
    public function render(LayoutRenderRequest $request): string;
}
