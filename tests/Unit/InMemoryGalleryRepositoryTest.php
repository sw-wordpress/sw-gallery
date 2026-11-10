<?php

declare(strict_types=1);

namespace SWGallery\Tests\Unit;

use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Tests\Support\GalleryRepositoryContract;
use SWGallery\Tests\Support\InMemoryGalleries;
use SWGallery\Tests\Support\InMemoryItems;

/** Runs the shared repository contract against the in-memory fake, so the fake cannot drift from the contract. */
final class InMemoryGalleryRepositoryTest extends GalleryRepositoryContract
{
    private InMemoryItems $items;
    private InMemoryGalleries $galleries;

    protected function setUp(): void
    {
        $this->items = new InMemoryItems();
        $this->galleries = new InMemoryGalleries($this->items);
    }

    protected function repository(): GalleryRepositoryInterface
    {
        return $this->galleries;
    }

    protected function seedItems(int $galleryId, array $attachmentIds): void
    {
        foreach ($attachmentIds as $attachmentId) {
            $this->items->append($galleryId, $attachmentId);
        }
    }

    protected function itemCount(int $galleryId): int
    {
        return $this->items->count($galleryId);
    }
}
