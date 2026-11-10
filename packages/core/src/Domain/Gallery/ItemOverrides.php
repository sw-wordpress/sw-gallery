<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/**
 * Gallery-local overrides of attachment metadata.
 *
 * `null` means "inherit from the attachment"; an empty string is an intentional blank override.
 */
final class ItemOverrides
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $caption = null,
        public readonly ?string $alt = null,
    ) {
    }

    public function resolveTitle(string $inherited): string
    {
        return $this->title ?? $inherited;
    }

    public function resolveCaption(string $inherited): string
    {
        return $this->caption ?? $inherited;
    }

    public function resolveAlt(string $inherited): string
    {
        return $this->alt ?? $inherited;
    }
}
