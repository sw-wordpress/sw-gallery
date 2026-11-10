<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Domain\Gallery\Revision;

interface RevisionStoreInterface
{
    public function current(int $galleryId): Revision;

    /**
     * Atomically bumps the revision if it still equals $expected.
     *
     * @throws \SWGallery\Core\Exception\RevisionConflictException When another writer got there first.
     */
    public function compareAndIncrement(int $galleryId, Revision $expected): Revision;
}
