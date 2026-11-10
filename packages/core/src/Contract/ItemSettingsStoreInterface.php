<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

/** Persistence of per-item layout settings (JSON-compatible map; unknown keys are preserved). */
interface ItemSettingsStoreInterface
{
    /**
     * @return array<string, mixed> Empty when the item has no settings.
     */
    public function itemSettings(int $galleryId, int $itemId): array;

    /**
     * Replaces the settings of the item.
     *
     * @param array<string, mixed> $settings
     * @return bool Whether the item exists in the gallery.
     */
    public function saveItemSettings(int $galleryId, int $itemId, array $settings): bool;
}
