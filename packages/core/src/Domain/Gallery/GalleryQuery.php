<?php

declare(strict_types=1);

namespace SWGallery\Core\Domain\Gallery;

/**
 * Bounded search of galleries. An empty status list means "every status except trash";
 * trashed galleries are listed only when GalleryStatus::Trash is requested explicitly.
 */
final class GalleryQuery
{
    public const MAX_LIMIT = 100;
    public const DEFAULT_LIMIT = 20;

    /**
     * @param list<GalleryStatus> $statuses
     */
    public function __construct(
        public readonly ?string $search = null,
        public readonly array $statuses = [],
        public readonly ?int $ownerId = null,
        public readonly int $offset = 0,
        public readonly int $limit = self::DEFAULT_LIMIT,
    ) {
        if ($offset < 0 || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('Pagination out of bounds.');
        }
        if ($ownerId !== null && $ownerId < 0) {
            throw new \InvalidArgumentException('Invalid owner id.');
        }
    }
}
