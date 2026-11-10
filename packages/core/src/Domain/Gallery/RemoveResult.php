<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/** Honest outcome of a bulk removal: only relations are removed, never attachments. */
final class RemoveResult
{
    /**
     * @param list<int> $removed  Item IDs whose relation was removed.
     * @param list<int> $notFound Item IDs that are not part of the gallery.
     */
    public function __construct(
        public readonly array $removed,
        public readonly array $notFound,
        public readonly Revision $revision,
    ) {
    }
}
