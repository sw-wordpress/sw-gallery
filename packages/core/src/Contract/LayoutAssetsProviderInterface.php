<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Application\Render\RenderAsset;

/**
 * Optional capability of a layout (additive to GalleryLayoutInterface): frontend assets its markup needs beyond the
 * lightbox (e.g. a progressive-enhancement script). The adapter enqueues them only when such a gallery rendered.
 */
interface LayoutAssetsProviderInterface
{
    /** @return list<RenderAsset> */
    public function renderAssets(): array;
}
