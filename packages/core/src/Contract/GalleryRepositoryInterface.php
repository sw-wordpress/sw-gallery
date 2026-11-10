<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryPage;
use SWGallery\Core\Domain\Gallery\GalleryQuery;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Core\Exception\GalleryNotFoundException;

/**
 * Persistence of gallery definitions. No method ever deletes or copies an attachment; revisions are
 * handled by the RevisionStore, not here. Reusable contract test: tests/Support/GalleryRepositoryContract.php.
 */
interface GalleryRepositoryInterface
{
    /** Finds a gallery in any status, including the trash. */
    public function find(int $id): ?Gallery;

    public function search(GalleryQuery $query): GalleryPage;

    public function create(NewGallery $new): Gallery;

    /**
     * Persists title, description, status, owner, cover and settings.
     * Moving into or out of the trash is done with trash()/restore(), never through update().
     *
     * @throws GalleryNotFoundException
     * @throws \InvalidArgumentException When the status is trash.
     */
    public function update(Gallery $gallery): void;

    /**
     * Copies definition, settings, cover, item membership and item overrides (not the media files).
     * The copy is a draft owned by $ownerId.
     *
     * @throws GalleryNotFoundException
     */
    public function duplicate(int $sourceId, string $title, int $ownerId): Gallery;

    /**
     * Moves the gallery to the trash. Item relations are kept so a restore is lossless.
     *
     * @throws GalleryNotFoundException
     */
    public function trash(int $id): void;

    /**
     * Restores a trashed gallery as a draft.
     *
     * @throws GalleryNotFoundException
     */
    public function restore(int $id): Gallery;

    /**
     * Permanently deletes the gallery and its item relations. Attachments stay untouched.
     *
     * @throws GalleryNotFoundException
     */
    public function delete(int $id): void;
}
