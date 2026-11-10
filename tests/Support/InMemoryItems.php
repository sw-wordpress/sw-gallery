<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\GalleryItemRepositoryInterface;
use SWGallery\Core\Contract\ItemOverridesStoreInterface;
use SWGallery\Core\Contract\ItemSettingsStoreInterface;
use SWGallery\Core\Contract\MediaRepositoryInterface;
use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Exception\RevisionConflictException;

/**
 * Single in-memory fake implementing the repository contracts used by the item services. It can snapshot its state
 * for InMemoryTransactionRunner and fail a write on demand to exercise rollback.
 */
final class InMemoryItems implements
    GalleryItemRepositoryInterface,
    RevisionStoreInterface,
    MediaRepositoryInterface,
    ItemOverridesStoreInterface,
    ItemSettingsStoreInterface,
    Snapshotable
{
    /** @var array<int, array<int, array{id: int, attachment: int, position: int}>> */
    private array $rows = [];
    /** @var array<int, int> */
    private array $revisions = [];
    /** @var array<int, array<int, ItemOverrides>> */
    private array $overrides = [];
    /** @var array<int, array<int, array<string, mixed>>> */
    private array $settings = [];
    private int $nextId = 1;
    private ?int $writesBeforeFailure = null;

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
            fn (array $r): GalleryItem => new GalleryItem(
                $r['id'],
                $galleryId,
                $r['attachment'],
                $r['position'],
                $this->overrides[$galleryId][$r['id']] ?? new ItemOverrides()
            ),
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
        $this->guardWrite();
        $position = count($this->rows[$galleryId] ?? []) + 1;
        $id = $this->nextId++;
        $this->rows[$galleryId][$id] = ['id' => $id, 'attachment' => $attachmentId, 'position' => $position];

        return new GalleryItem($id, $galleryId, $attachmentId, $position);
    }

    public function remove(int $galleryId, int $itemId): bool
    {
        $this->guardWrite();
        $existed = isset($this->rows[$galleryId][$itemId]);
        unset(
            $this->rows[$galleryId][$itemId],
            $this->overrides[$galleryId][$itemId],
            $this->settings[$galleryId][$itemId]
        );

        return $existed;
    }

    public function applyOrder(int $galleryId, array $itemIds): void
    {
        $this->guardWrite();
        foreach ($itemIds as $index => $id) {
            $this->rows[$galleryId][$id]['position'] = $index + 1;
        }
    }

    /** Copies the membership of one gallery to another (used by the in-memory gallery repository). */
    public function copyGallery(int $sourceId, int $targetId): void
    {
        foreach ($this->findPage($sourceId, 0, PHP_INT_MAX) as $item) {
            $copy = $this->append($targetId, $item->attachmentId);
            $this->overrides[$targetId][$copy->id] = $item->overrides;
            $this->settings[$targetId][$copy->id] = $this->settings[$sourceId][$item->id] ?? [];
        }
    }

    /** Removes every relation of a gallery (permanent gallery deletion). */
    public function removeGallery(int $galleryId): void
    {
        unset(
            $this->rows[$galleryId],
            $this->revisions[$galleryId],
            $this->overrides[$galleryId],
            $this->settings[$galleryId]
        );
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

    public function saveOverrides(int $galleryId, int $itemId, ItemOverrides $overrides): bool
    {
        $this->guardWrite();
        if (!isset($this->rows[$galleryId][$itemId])) {
            return false;
        }
        $this->overrides[$galleryId][$itemId] = $overrides;

        return true;
    }

    public function itemSettings(int $galleryId, int $itemId): array
    {
        return $this->settings[$galleryId][$itemId] ?? [];
    }

    public function saveItemSettings(int $galleryId, int $itemId, array $settings): bool
    {
        $this->guardWrite();
        if (!isset($this->rows[$galleryId][$itemId])) {
            return false;
        }
        $this->settings[$galleryId][$itemId] = $settings;

        return true;
    }

    /** Lets the next $writes item writes succeed and then throws on every further write (null disables). */
    public function failAfterWrites(?int $writes): void
    {
        $this->writesBeforeFailure = $writes;
    }

    public function captureState(): mixed
    {
        return [$this->rows, $this->revisions, $this->overrides, $this->settings, $this->nextId];
    }

    public function restoreState(mixed $snapshot): void
    {
        [$this->rows, $this->revisions, $this->overrides, $this->settings, $this->nextId] = $snapshot;
    }

    private function guardWrite(): void
    {
        if ($this->writesBeforeFailure === null) {
            return;
        }
        if ($this->writesBeforeFailure <= 0) {
            throw new \RuntimeException('Simulated write failure.');
        }
        --$this->writesBeforeFailure;
    }
}
