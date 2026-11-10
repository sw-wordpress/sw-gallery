<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/** Stored item settings (schema defaults applied, unknown keys preserved) and the new revision. */
final class ItemSettingsResult
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        public readonly array $settings,
        public readonly Revision $revision,
    ) {
    }
}
