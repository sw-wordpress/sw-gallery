<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\SimpleMarkupRendererInterface;
use SWGallery\Core\Domain\Layout\LayoutRenderRequest;

/** Simplified markup double. */
final class RecordingSimpleMarkup implements SimpleMarkupRendererInterface
{
    public ?LayoutRenderRequest $last = null;

    public function render(LayoutRenderRequest $request): string
    {
        $this->last = $request;

        return '<ul>simple ' . count($request->items) . '</ul>';
    }
}
