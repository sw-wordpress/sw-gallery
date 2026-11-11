<?php
/**
 * Builds the JSON shape of gallery items (docs/rest-api.md).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Rest;

use SWGallery\Core\Contract\MediaReferenceRepositoryInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Core\Domain\Media\MediaReference;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;

/**
 * Metadata of a whole page comes from one batched `findReferences()` call (posts and meta primed in bulk), so the
 * number of queries does not grow with the page size. An item whose attachment is gone, is not a usable image, or may
 * not be used by the actor is `missing`: it stays listed (so the admin can remove it) but exposes no metadata and
 * no URLs, which also keeps the existence of attachments the actor may not read private (D11).
 */
final class ItemPresenter {

	public function __construct(
		private readonly MediaReferenceRepositoryInterface $references,
		private readonly WpGalleryAccessPolicy $access
	) {
	}

	/**
	 * @param Actor     $actor Requesting actor.
	 * @param ItemRow[] $rows  Items of one page.
	 * @phpstan-param list<ItemRow> $rows
	 * @return list<array<string, mixed>>
	 */
	public function present_many( Actor $actor, array $rows ): array {
		$ids        = array_map( static fn( ItemRow $row ): int => $row->item->attachmentId, $rows );
		$references = array() === $ids ? array() : $this->references->findReferences( $ids );
		$data       = array();
		foreach ( $rows as $row ) {
			$reference = $references[ $row->item->attachmentId ] ?? null;
			if ( null !== $reference && ! $this->access->canUseAttachment( $actor, $row->item->attachmentId ) ) {
				$reference = null;
			}
			$data[] = $this->present_row( $row, $reference );
		}
		return $data;
	}

	/**
	 * @param Actor   $actor Requesting actor.
	 * @param ItemRow $row   The item.
	 * @return array<string, mixed>
	 */
	public function present( Actor $actor, ItemRow $row ): array {
		return $this->present_many( $actor, array( $row ) )[0];
	}

	/**
	 * @param ItemRow             $row       The item.
	 * @param MediaReference|null $reference Usable attachment metadata, null when missing.
	 * @return array<string, mixed>
	 */
	private function present_row( ItemRow $row, ?MediaReference $reference ): array {
		$overrides = $row->item->overrides;
		$global    = null === $reference ? null : array(
			'title'   => $reference->title,
			'caption' => $reference->caption,
			'alt'     => $reference->alt,
		);
		return array(
			'id'           => $row->item->id,
			'attachmentId' => $row->item->attachmentId,
			'position'     => $row->item->position,
			'overrides'    => $this->overrides( $overrides ),
			'effective'    => array(
				'title'   => $overrides->resolveTitle( $global['title'] ?? '' ),
				'caption' => $overrides->resolveCaption( $global['caption'] ?? '' ),
				'alt'     => $overrides->resolveAlt( $global['alt'] ?? '' ),
			),
			'global'       => $global,
			'settings'     => (object) $row->settings,
			'media'        => null === $reference ? null : $this->media( $reference ),
			'missing'      => null === $reference,
		);
	}

	/**
	 * Core size URLs only; no file names, paths or metadata arrays.
	 *
	 * @param MediaReference $reference Usable attachment.
	 * @return array<string, mixed>
	 */
	private function media( MediaReference $reference ): array {
		$thumbnail = wp_get_attachment_image_url( $reference->attachmentId, 'medium' );
		$full      = wp_get_attachment_url( $reference->attachmentId );
		return array(
			'mimeType'     => $reference->mimeType,
			'width'        => $reference->width,
			'height'       => $reference->height,
			'thumbnailUrl' => is_string( $thumbnail ) && '' !== $thumbnail ? $thumbnail : null,
			'fullUrl'      => is_string( $full ) && '' !== $full ? $full : null,
		);
	}

	/**
	 * `null` (inherit) and `''` (intentional blank) are serialised faithfully.
	 *
	 * @param ItemOverrides $overrides Stored overrides.
	 * @return array<string, string|null>
	 */
	private function overrides( ItemOverrides $overrides ): array {
		return array(
			'title'   => $overrides->title,
			'caption' => $overrides->caption,
			'alt'     => $overrides->alt,
		);
	}
}
