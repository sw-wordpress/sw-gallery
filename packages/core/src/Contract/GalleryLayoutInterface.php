<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Layout\LayoutRenderRequest;

/**
 * A gallery presentation strategy (grid, masonry, ...). Public extension point; the contract is versioned,
 * breaking changes need a new interface (see docs/extending.md, compatibility policy).
 *
 * Registration validates the contract: `getId()` must match GallerySettings::LAYOUT_ID_PATTERN and
 * `getSettingsSchema()` must be a non-empty array; an object that fails is rejected (never registered).
 *
 * @since 1.0.0
 */
interface GalleryLayoutInterface
{
    /** Version of this contract. It only changes together with a new interface (major release). */
    public const API_VERSION = 1;

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
