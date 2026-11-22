<?php
/**
 * Gallery list with the visibility rules shared by REST and the MCP abilities.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Gallery;

use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Contract\RevisionStoreInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryQuery;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Rest\GalleryPresenter;

/**
 * Visibility rule: users with `edit_others_posts` and `read_private_posts` see galleries of all owners (and may filter
 * by owner); everybody else only ever sees their own. Listing the trash needs `delete_posts`, and `delete_others_posts`
 * to include foreign trashed galleries.
 */
final class GalleryLister {

	/** Statuses a list may be filtered by. */
	public const LIST_STATUSES = array( 'draft', 'pending', 'publish', 'private', 'trash' );

	public function __construct(
		private readonly GalleryRepositoryInterface $galleries,
		private readonly WpdbGalleryRelations $relations,
		private readonly RevisionStoreInterface $revisions,
		private readonly GalleryPresenter $presenter
	) {
	}

	/**
	 * @param mixed $values Submitted status list.
	 * @return list<GalleryStatus> Known, deduplicated statuses.
	 */
	public static function parse_statuses( mixed $values ): array {
		$statuses = array();
		foreach ( (array) $values as $value ) {
			$status = GalleryStatus::tryFrom( (string) $value );
			if ( null !== $status && ! in_array( $status, $statuses, true ) ) {
				$statuses[] = $status;
			}
		}
		return $statuses;
	}

	/**
	 * @param Actor           $actor    Actor.
	 * @param string|null     $search   Search text.
	 * @param GalleryStatus[] $statuses Requested statuses (empty = all but trash).
	 * @phpstan-param list<GalleryStatus> $statuses
	 * @param int|null        $owner    Requested owner filter (honoured only for users who see everything).
	 * @param int             $page     Page, from 1.
	 * @param int             $per_page Page size.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 * @throws AccessDeniedException When the trash is requested without the right to see it.
	 */
	public function list( Actor $actor, ?string $search, array $statuses, ?int $owner, int $page, int $per_page ): array {
		$trash = in_array( GalleryStatus::Trash, $statuses, true );
		if ( $trash && ! current_user_can( 'delete_posts' ) ) {
			throw new AccessDeniedException();
		}
		$sees_all = current_user_can( 'edit_others_posts' ) && current_user_can( 'read_private_posts' )
			&& ( ! $trash || current_user_can( 'delete_others_posts' ) );
		$owner    = $sees_all ? $owner : $actor->userId;
		$term     = null !== $search && '' !== $search ? $search : null;
		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );

		$result = $this->galleries->search( new GalleryQuery( $term, $statuses, $owner, ( $page - 1 ) * $per_page, $per_page ) );
		$total  = $result->total;
		if ( array() === $result->items && $page > 1 ) {
			// WP_Query reports no total for an offset past the end; ask again so the client can step back.
			$total = $this->galleries->search( new GalleryQuery( $term, $statuses, $owner, 0, 1 ) )->total;
		}
		$ids    = array_map( static fn( Gallery $gallery ): int => $gallery->id, $result->items );
		$counts = $this->relations->count_by_gallery( $ids );
		$items  = array();
		foreach ( $result->items as $gallery ) {
			$items[] = $this->presenter->present( $actor, $gallery, $this->revisions->current( $gallery->id )->value, $counts[ $gallery->id ] ?? 0 );
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
