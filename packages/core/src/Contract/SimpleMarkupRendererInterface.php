<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Layout\LayoutRenderRequest;

/**
 * Renders the simplified markup used where scripts, styles and data attributes cannot be relied on (feeds, excerpts,
 * `content.rendered`-like contexts): a plain list of linked images with alt text and an optional caption.
 */
interface SimpleMarkupRendererInterface
{
    public function render(LayoutRenderRequest $request): string;
}
