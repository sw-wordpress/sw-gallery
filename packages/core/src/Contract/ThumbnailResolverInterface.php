<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Layout\ImageRequest;
use SWGallery\Core\Domain\Layout\ResolvedImage;

/** Chooses image variants (URL, srcset, dimensions). Uses only sizes the site already registers. */
interface ThumbnailResolverInterface
{
    /** @return ResolvedImage|null Null when the attachment is missing or not a usable image. */
    public function resolve(ImageRequest $request): ?ResolvedImage;
}
