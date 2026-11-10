<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Gallery\ItemOverrides;

/** Persistence of gallery-local item overrides. Never touches the attachment's own metadata. */
interface ItemOverridesStoreInterface
{
    /**
     * Replaces all three overrides of the item: `null` stores "inherit from the attachment",
     * an empty string stores an intentional blank.
     *
     * @return bool Whether the item exists in the gallery.
     */
    public function saveOverrides(int $galleryId, int $itemId, ItemOverrides $overrides): bool;
}
