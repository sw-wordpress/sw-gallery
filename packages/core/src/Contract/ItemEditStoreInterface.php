<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Gallery\ItemOverrides;

interface ItemEditStoreInterface extends ItemOverridesStoreInterface, ItemSettingsStoreInterface
{
    /**
     * Writes the supplied fields as a single operation. Null keeps that part of the item unchanged.
     *
     * @param array<string, mixed>|null $settings
     */
    public function saveItemEdit(int $galleryId, int $itemId, ?ItemOverrides $overrides, ?array $settings): bool;
}
