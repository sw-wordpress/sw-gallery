<?php

declare(strict_types=1);

namespace SWGallery\Core\Contract;

use SWGallery\Core\Application\Render\RenderItemPage;
use SWGallery\Core\Domain\Gallery\SortMode;

/**
 * Read model of the renderer: one bounded page of items ordered by the gallery's sort mode (decision D3) together with
 * the total. Items whose attachment is missing, trashed or not a usable image are skipped (and not counted) without
 * touching any relation; implementations never load all items to answer a page.
 */
interface RenderItemSourceInterface
{
    /**
     * Manual mode uses the stored positions; date modes the attachment upload date; title modes the attachment
     * title. Ties are broken by ascending item id, so the order is stable.
     *
     * @param int $offset Zero-based.
     * @param int $limit  Page size, clamped to 1..500 by implementations.
     * @param bool $publicOnly Exclude protected media from both pagination and totals.
     */
    public function findRenderPage(int $galleryId, SortMode $sortMode, int $offset, int $limit, bool $publicOnly = false): RenderItemPage;
}
