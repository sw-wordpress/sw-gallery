<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryPage;
use SWGallery\Core\Domain\Gallery\GalleryQuery;
use SWGallery\Core\Domain\Gallery\NewGallery;

/** Gallery repository double that only answers `find()` (the renderer's single use). */
final class FixedGalleries implements GalleryRepositoryInterface
{
    /** @var array<int, Gallery> */
    private array $galleries = [];

    public function __construct(Gallery ...$galleries)
    {
        foreach ($galleries as $gallery) {
            $this->galleries[$gallery->id] = $gallery;
        }
    }

    public function find(int $id): ?Gallery
    {
        return $this->galleries[$id] ?? null;
    }

    public function search(GalleryQuery $query): GalleryPage
    {
        throw new \LogicException('Not used.');
    }

    public function create(NewGallery $new): Gallery
    {
        throw new \LogicException('Not used.');
    }

    public function update(Gallery $gallery): void
    {
        throw new \LogicException('Not used.');
    }

    public function duplicate(int $sourceId, string $title, int $ownerId): Gallery
    {
        throw new \LogicException('Not used.');
    }

    public function trash(int $id): void
    {
        throw new \LogicException('Not used.');
    }

    public function restore(int $id): Gallery
    {
        throw new \LogicException('Not used.');
    }

    public function delete(int $id): void
    {
        throw new \LogicException('Not used.');
    }
}
