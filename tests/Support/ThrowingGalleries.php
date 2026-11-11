<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryPage;
use SWGallery\Core\Domain\Gallery\GalleryQuery;
use SWGallery\Core\Domain\Gallery\NewGallery;

/** Repository whose every operation fails with an exception full of details that must never reach a response. */
final class ThrowingGalleries implements GalleryRepositoryInterface
{
    public const SECRET = "SELECT secret FROM wp_users WHERE path='/var/www/secret.php'";

    public function find(int $id): ?Gallery
    {
        throw new \RuntimeException(self::SECRET);
    }

    public function search(GalleryQuery $query): GalleryPage
    {
        throw new \RuntimeException(self::SECRET);
    }

    public function create(NewGallery $new): Gallery
    {
        throw new \RuntimeException(self::SECRET);
    }

    public function update(Gallery $gallery): void
    {
        throw new \RuntimeException(self::SECRET);
    }

    public function duplicate(int $sourceId, string $title, int $ownerId): Gallery
    {
        throw new \RuntimeException(self::SECRET);
    }

    public function trash(int $id): void
    {
        throw new \RuntimeException(self::SECRET);
    }

    public function restore(int $id): Gallery
    {
        throw new \RuntimeException(self::SECRET);
    }

    public function delete(int $id): void
    {
        throw new \RuntimeException(self::SECRET);
    }
}
