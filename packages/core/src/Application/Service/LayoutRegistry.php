<?php

declare(strict_types=1);

namespace SWGallery\Core\Application\Service;

use SWGallery\Core\Contract\GalleryLayoutInterface;
use SWGallery\Core\Contract\LayoutRegistryInterface;
use SWGallery\Core\Domain\Layout\LayoutResolution;
use SWGallery\Core\Exception\UnknownLayoutException;

final class LayoutRegistry implements LayoutRegistryInterface
{
    /** @var array<string, GalleryLayoutInterface> */
    private array $layouts = [];

    public function register(GalleryLayoutInterface $layout): void
    {
        $id = $layout->getId();
        if (isset($this->layouts[$id])) {
            throw new \InvalidArgumentException('A layout with this id is already registered.');
        }
        $this->layouts[$id] = $layout;
    }

    public function has(string $id): bool
    {
        return isset($this->layouts[$id]);
    }

    public function get(string $id): GalleryLayoutInterface
    {
        return $this->layouts[$id] ?? throw new UnknownLayoutException();
    }

    public function resolve(string $id): LayoutResolution
    {
        if (isset($this->layouts[$id])) {
            return new LayoutResolution($this->layouts[$id], $id, false);
        }
        $fallback = $this->layouts[self::FALLBACK_ID]
            ?? throw new \LogicException('The fallback layout is not registered.');

        return new LayoutResolution($fallback, $id, true);
    }

    public function all(): array
    {
        return array_values($this->layouts);
    }
}
