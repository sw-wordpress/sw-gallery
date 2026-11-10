<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/**
 * Presentation settings of one gallery.
 *
 * Layout-specific values are kept as an opaque map: keys written by a newer plugin version survive a
 * load/save round trip and are ignored when rendering. Validation against layout schemas is added with M1-05.
 */
final class GallerySettings
{
    public const SCHEMA_VERSION = 1;
    public const DEFAULT_LAYOUT = 'grid';
    public const LAYOUT_ID_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

    /**
     * @param array<string, mixed> $values Layout-specific settings, unknown keys preserved.
     */
    public function __construct(
        public readonly string $layout = self::DEFAULT_LAYOUT,
        public readonly SortMode $sortMode = SortMode::Manual,
        public readonly array $values = [],
        public readonly int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if (preg_match(self::LAYOUT_ID_PATTERN, $layout) !== 1) {
            throw new \InvalidArgumentException('Invalid layout id.');
        }
        if ($schemaVersion < 1) {
            throw new \InvalidArgumentException('The settings schema version must be positive.');
        }
    }

    public function withLayout(string $layout): self
    {
        return new self($layout, $this->sortMode, $this->values, $this->schemaVersion);
    }

    public function withSortMode(SortMode $sortMode): self
    {
        return new self($this->layout, $sortMode, $this->values, $this->schemaVersion);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function withValues(array $values): self
    {
        return new self($this->layout, $this->sortMode, $values, $this->schemaVersion);
    }
}
