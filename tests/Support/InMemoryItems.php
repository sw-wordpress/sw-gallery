<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\GalleryItemRepositoryInterface;
use SWGallery\Core\Contract\MediaRepositoryInterface;
use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Exception\RevisionConflictException;

/** Single in-memory fake implementing the three repository contracts used by GalleryItemService. */
final class InMemoryItems implements GalleryItemRepositoryInterface, RevisionStoreInterface, MediaRepositoryInterface
{
    /** @var array<int, array<int, array{id: int, attachment: int, position: int}>> */
    private array $rows = [];
    /** @var array<int, int> */
    private array $revisions = [];
    private int $nextId = 1;
    /**
     * @param list<int> $knownImages
     */
    public function __construct(private array $knownImages = [])
    {
    }

    public function findPage(int $galleryId, int $offset, int $limit): array
    {
        $rows = array_values($this->rows[$galleryId] ?? []);
        usort(
            $rows,
            static fn (array $a, array $b): int => [$a['position'], $a['id']] <=> [$b['position'], $b['id']]
        );

        return array_map(
            static fn (array $r): GalleryItem => new GalleryItem($r['id'], $galleryId, $r['attachment'], $r['position']),
            array_slice($rows, $offset, $limit)
        );
    }

    public function count(int $galleryId): int
    {
        return count($this->rows[$galleryId] ?? []);
    }

    public function orderedIds(int $galleryId): array
    {
        return array_map(static fn (GalleryItem $i): int => $i->id, $this->findPage($galleryId, 0, PHP_INT_MAX));
    }

    public function assignedAttachmentIds(int $galleryId, array $attachmentIds): array
    {
        $have = array_column($this->rows[$galleryId] ?? [], 'attachment');

        return array_values(array_intersect($attachmentIds, $have));
    }

    public function append(int $galleryId, int $attachmentId): GalleryItem
    {
        $position = count($this->rows[$galleryId] ?? []) + 1;
        $id = $this->nextId++;
        $this->rows[$galleryId][$id] = ['id' => $id, 'attachment' => $attachmentId, 'position' => $position];

        return new GalleryItem($id, $galleryId, $attachmentId, $position);
    }

    public function remove(int $galleryId, int $itemId): bool
    {
        $existed = isset($this->rows[$galleryId][$itemId]);
        unset($this->rows[$galleryId][$itemId]);

        return $existed;
    }

    public function applyOrder(int $galleryId, array $itemIds): void
    {
        foreach ($itemIds as $index => $id) {
            $this->rows[$galleryId][$id]['position'] = $index + 1;
        }
    }

    public function current(int $galleryId): Revision
    {
        return new Revision($this->revisions[$galleryId] ?? 0);
    }

    public function compareAndIncrement(int $galleryId, Revision $expected): Revision
    {
        $actual = $this->current($galleryId);
        if (!$actual->equals($expected)) {
            throw new RevisionConflictException($expected->value, $actual->value);
        }

        return new Revision($this->revisions[$galleryId] = $actual->value + 1);
    }

    public function existingImageIds(array $attachmentIds): array
    {
        return array_values(array_intersect($attachmentIds, $this->knownImages));
    }
}
