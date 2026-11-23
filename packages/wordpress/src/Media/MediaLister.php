<?php
/**
 * Image list of the media library with the visibility rules shared by REST and the MCP abilities.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Media;

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Rest\MediaPresenter;
use SWGallery\WordPress\Rest\MediaSearch;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;

/**
 * Decision D11: only images the actor may use. Only users with `edit_others_posts` can filter by another uploader.
 */
final class MediaLister {

	public function __construct(
		private readonly MediaSearch $search,
		private readonly MediaPresenter $presenter,
		private readonly WpdbGalleryRelations $relations,
		private readonly WpGalleryAccessPolicy $access
	) {
	}

	/**
	 * @param Actor  $actor       Actor.
	 * @param string $search      Search text.
	 * @param int    $page        Page, from 1.
	 * @param int    $per_page    Page size.
	 * @param string $orderby     `date` or `title`.
	 * @param string $order       `asc` or `desc`.
	 * @param int    $uploaded_by Uploader filter (0 = none; forced to the caller without `edit_others_posts`).
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public function list( Actor $actor, string $search, int $page, int $per_page, string $orderby, string $order, int $uploaded_by ): array {
		if ( $uploaded_by > 0 && ! current_user_can( 'edit_others_posts' ) ) {
			$uploaded_by = $actor->userId;
		}
		$found = $this->search->search( $actor->userId, $search, $page, $per_page, $orderby, $order, $uploaded_by );
		$posts = array_values(
			array_filter(
				$found['posts'],
				fn( \WP_Post $post ): bool => $this->access->canUseAttachment( $actor, $post->ID )
			)
		);
		$ids   = array_map( static fn( \WP_Post $post ): int => $post->ID, $posts );
		$used  = $this->relations->count_galleries_by_attachment( $ids );
		$items = array();
		foreach ( $posts as $post ) {
			$items[] = $this->presenter->present( $post, $used[ $post->ID ] ?? 0 );
		}
		return array(
			'items' => $items,
			'total' => $found['total'],
		);
	}
}
