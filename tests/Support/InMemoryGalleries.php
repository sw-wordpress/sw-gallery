<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryPage;
use SWGallery\Core\Domain\Gallery\GalleryQuery;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Core\Exception\GalleryNotFoundException;

/** In-memory fake of the gallery repository; membership lives in the shared InMemoryItems fake. */
final class InMemoryGalleries implements GalleryRepositoryInterface
{
    /** @var array<int, Gallery> */
    private array $galleries = [];
    private int $nextId = 1;

    public function __construct(private readonly InMemoryItems $items = new InMemoryItems())
    {
    }

    public function find(int $id): ?Gallery
    {
        return $this->galleries[$id] ?? null;
    }

    public function search(GalleryQuery $query): GalleryPage
    {
        $matches = array_values(array_filter(
            $this->galleries,
            static function (Gallery $g) use ($query): bool {
                $statusOk = $query->statuses === []
                    ? $g->status !== GalleryStatus::Trash
                    : in_array($g->status, $query->statuses, true);
                $ownerOk = $query->ownerId === null || $g->ownerId === $query->ownerId;
                $searchOk = $query->search === null
                    || stripos($g->title, $query->search) !== false
                    || stripos($g->description, $query->search) !== false;

                return $statusOk && $ownerOk && $searchOk;
            }
        ));
        usort($matches, static fn (Gallery $a, Gallery $b): int => $a->id <=> $b->id);

        return new GalleryPage(array_slice($matches, $query->offset, $query->limit), count($matches));
    }

    public function create(NewGallery $new): Gallery
    {
        $id = $this->nextId++;

        return $this->galleries[$id] = new Gallery(
            $id,
            $new->title,
            $new->description,
            $new->status,
            $new->ownerId,
            null,
            $new->settings,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }

    public function update(Gallery $gallery): void
    {
        $this->require($gallery->id);
        if ($gallery->status === GalleryStatus::Trash) {
            throw new \InvalidArgumentException('Use trash() to move a gallery to the trash.');
        }
        $this->galleries[$gallery->id] = $gallery;
    }

    public function duplicate(int $sourceId, string $title, int $ownerId): Gallery
    {
        $source = $this->require($sourceId);
        $id = $this->nextId++;
        $copy = new Gallery(
            $id,
            $title,
            $source->description,
            GalleryStatus::Draft,
            $ownerId,
            $source->coverAttachmentId,
            $source->settings,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
        $this->items->copyGallery($sourceId, $id);

        return $this->galleries[$id] = $copy;
    }

    public function trash(int $id): void
    {
        $this->galleries[$id] = $this->require($id)->withStatus(GalleryStatus::Trash);
    }

    public function restore(int $id): Gallery
    {
        return $this->galleries[$id] = $this->require($id)->withStatus(GalleryStatus::Draft);
    }

    public function delete(int $id): void
    {
        $this->require($id);
        unset($this->galleries[$id]);
        $this->items->removeGallery($id);
    }

    private function require(int $id): Gallery
    {
        return $this->galleries[$id] ?? throw new GalleryNotFoundException();
    }
}
