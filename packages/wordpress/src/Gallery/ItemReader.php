<?php
/**
 * Paged, access-filtered read of a gallery's items for the MCP abilities.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Gallery;

use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;
use SWGallery\WordPress\Rest\ItemPresenter;

/**
 * Same repository query and presenter as `GET /galleries/{id}/items`; a missing or not viewable gallery is 404.
 */
final class ItemReader {

	public function __construct(
		private readonly GalleryEditor $editor,
		private readonly WpdbGalleryItemRepository $items,
		private readonly ItemPresenter $presenter,
		private readonly RevisionStoreInterface $revisions
	) {
	}

	/**
	 * @param Actor $actor    Actor.
	 * @param int   $id       Gallery id.
	 * @param int   $page     Page, from 1.
	 * @param int   $per_page Page size.
	 * @return array{items: list<array<string, mixed>>, total: int, revision: int}
	 * @throws GalleryNotFoundException When missing or not viewable.
	 */
	public function page( Actor $actor, int $id, int $page, int $per_page ): array {
		$gallery  = $this->editor->viewable( $actor, $id );
		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );
		$rows     = $this->items->findPageWithSettings( $gallery->id, ( $page - 1 ) * $per_page, $per_page );
		return array(
			'items'    => $this->presenter->present_many( $actor, $rows ),
			'total'    => $this->items->count( $gallery->id ),
			'revision' => $this->revisions->current( $gallery->id )->value,
		);
	}
}
