<?php
/**
 * Item writes shared by REST and the MCP abilities.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Gallery;

use SWGallery\Core\Application\Service\GalleryItemEditService;
use SWGallery\Core\Application\Service\GalleryItemService;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\AssignResult;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Core\Domain\Gallery\RemoveResult;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Exception\ItemNotFoundException;
use SWGallery\WordPress\Repository\ItemRecord;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;
use SWGallery\WordPress\Rest\ErrorMapper;
use SWGallery\WordPress\Rest\ItemPresenter;

/**
 * The gallery must be viewable (404 otherwise) and, for writes, editable; the core services enforce the access policy,
 * the revision and the attachment rights again. Texts are plain text (markup is stripped here, like on the REST
 * boundary, so every entry point stores the same).
 */
final class ItemEditor {

	public function __construct(
		private readonly GalleryEditor $galleries,
		private readonly GalleryItemService $items,
		private readonly GalleryItemEditService $edits,
		private readonly WpdbGalleryItemRepository $repository,
		private readonly ItemPresenter $presenter
	) {
	}

	/**
	 * Sanitizer of an `overrides` map: plain text only (`caption` keeps line breaks), `null` stays `null`.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize_overrides( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $field => $text ) {
			if ( ! is_string( $text ) ) {
				continue;
			}
			$value[ $field ] = 'caption' === $field ? sanitize_textarea_field( $text ) : sanitize_text_field( $text );
		}
		return $value;
	}

	/**
	 * Adds attachments (the service reports skipped ids).
	 *
	 * @param Actor $actor          Actor.
	 * @param int   $id             Gallery id.
	 * @param int[] $attachment_ids Attachment ids.
	 * @param int   $expected       Revision the caller last saw.
	 * @phpstan-param list<int> $attachment_ids
	 */
	public function assign( Actor $actor, int $id, array $attachment_ids, int $expected ): AssignResult {
		$gallery = $this->galleries->viewable( $actor, $id );
		// The access checks and the existence query of the service read every attachment: one query for the batch.
		_prime_post_caches( array_slice( array_values( array_filter( $attachment_ids, static fn( int $item ): bool => $item > 0 ) ), 0, GalleryItemService::MAX_BATCH ), false, false );
		return $this->items->assign( $actor, $gallery->id, $attachment_ids, new Revision( $expected ) );
	}

	/**
	 * @param Actor $actor    Actor.
	 * @param int   $id       Gallery id.
	 * @param int[] $item_ids Relation ids to remove.
	 * @param int   $expected Revision the caller last saw.
	 * @phpstan-param list<int> $item_ids
	 */
	public function remove( Actor $actor, int $id, array $item_ids, int $expected ): RemoveResult {
		$gallery = $this->galleries->viewable( $actor, $id );
		return $this->items->removeMany( $actor, $gallery->id, $item_ids, new Revision( $expected ) );
	}

	/**
	 * @param Actor $actor    Actor.
	 * @param int   $id       Gallery id.
	 * @param int[] $item_ids Complete ordered list of item ids.
	 * @param int   $expected Revision the caller last saw.
	 * @phpstan-param list<int> $item_ids
	 * @return int The new revision.
	 */
	public function reorder( Actor $actor, int $id, array $item_ids, int $expected ): int {
		$gallery = $this->galleries->viewable( $actor, $id );
		return $this->items->reorder( $actor, $gallery->id, $item_ids, new Revision( $expected ) )->value;
	}

	/**
	 * Partial update: absent override fields keep their value, `null` inherits, `''` blanks; `settings` replaces the
	 * item's map. Both parts share one revision and one write.
	 *
	 * @param Actor                $actor     Actor.
	 * @param int                  $id        Gallery id.
	 * @param int                  $item_id   Item id.
	 * @param array<string, mixed> $overrides Submitted override fields (may be empty).
	 * @param array<mixed>|null    $settings  Replacement settings map (a list is rejected), or null to keep.
	 * @param int                  $expected  Revision the caller last saw.
	 * @return array{item: array<string, mixed>, revision: int}|\WP_Error
	 */
	public function update( Actor $actor, int $id, int $item_id, array $overrides, ?array $settings, int $expected ): array|\WP_Error {
		$gallery = $this->galleries->editable( $actor, $id );
		if ( null !== $settings && array() !== $settings && array_is_list( $settings ) ) {
			return ErrorMapper::bad_request( 'rest_invalid_param', __( 'The settings must be an object.', 'sw-gallery' ) );
		}
		if ( array() === $overrides && null === $settings ) {
			return ErrorMapper::bad_request( GalleryEditor::CODE_NO_CHANGES, __( 'The request does not change anything.', 'sw-gallery' ) );
		}
		$overrides = (array) self::sanitize_overrides( $overrides );
		$current   = $this->stored( $gallery->id, $item_id );
		$values    = null;
		if ( null !== $settings ) {
			$values = array();
			foreach ( $settings as $key => $value ) {
				$values[ (string) $key ] = $value;
			}
		}
		$revision = $this->edits->updateItem(
			$actor,
			$gallery->id,
			$item_id,
			array() === $overrides ? null : $this->merged( $current->item->overrides, $overrides ),
			$values,
			new Revision( $expected )
		)->revision;
		return array(
			'item'     => $this->presenter->present( $actor, $this->stored( $gallery->id, $item_id ) ),
			'revision' => $revision->value,
		);
	}

	/**
	 * @throws ItemNotFoundException When the item is not in the gallery.
	 */
	private function stored( int $gallery_id, int $item_id ): ItemRecord {
		return $this->repository->findWithSettings( $gallery_id, $item_id ) ?? throw new ItemNotFoundException();
	}

	/**
	 * @param ItemOverrides        $current Stored overrides.
	 * @param array<string, mixed> $patch   Submitted (sanitized) fields.
	 */
	private function merged( ItemOverrides $current, array $patch ): ItemOverrides {
		return new ItemOverrides(
			array_key_exists( 'title', $patch ) ? self::nullable_text( $patch['title'] ) : $current->title,
			array_key_exists( 'caption', $patch ) ? self::nullable_text( $patch['caption'] ) : $current->caption,
			array_key_exists( 'alt', $patch ) ? self::nullable_text( $patch['alt'] ) : $current->alt
		);
	}

	private static function nullable_text( mixed $value ): ?string {
		return is_string( $value ) ? $value : null;
	}
}
