<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Layout\LayoutResolution;
use SWGallery\Core\Exception\UnknownLayoutException;

interface LayoutRegistryInterface
{
    public const FALLBACK_ID = 'grid';

    /** @throws \InvalidArgumentException When the id or the schema is invalid or a layout with the same id is already registered. */
    public function register(GalleryLayoutInterface $layout): void;

    public function has(string $id): bool;

    /** @throws UnknownLayoutException */
    public function get(string $id): GalleryLayoutInterface;

    /**
     * Returns the requested layout or the `grid` fallback with `fellBack` set.
     *
     * @throws \LogicException When the fallback layout itself is not registered.
     */
    public function resolve(string $id): LayoutResolution;

    /** @return list<GalleryLayoutInterface> In registration order. */
    public function all(): array;
}
