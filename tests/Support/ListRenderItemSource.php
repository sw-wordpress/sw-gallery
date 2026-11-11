<?php

declare(strict_types=1);

namespace SWGallery\Tests\Support;

use SWGallery\Core\Application\Render\RenderItemPage;
use SWGallery\Core\Contract\RenderItemSourceInterface;
use SWGallery\Core\Domain\Gallery\GalleryItem;
use SWGallery\Core\Domain\Gallery\SortMode;

/** Item source over a fixed list: skips unusable attachments and records the requested sort mode and window. */
final class ListRenderItemSource implements RenderItemSourceInterface
{
    public ?SortMode $lastSortMode = null;
    public int $calls = 0;

    /**
     * @param list<GalleryItem> $items In manual order.
     * @param list<int>         $unusableAttachments
     */
    public function __construct(private readonly array $items, private readonly array $unusableAttachments = [])
    {
    }

    public function findRenderPage(int $galleryId, SortMode $sortMode, int $offset, int $limit): RenderItemPage
    {
        ++$this->calls;
        $this->lastSortMode = $sortMode;
        $usable = array_values(array_filter(
            $this->items,
            fn (GalleryItem $i): bool => $i->galleryId === $galleryId && !in_array($i->attachmentId, $this->unusableAttachments, true)
        ));

        return new RenderItemPage(array_slice($usable, $offset, $limit), count($usable));
    }
}
