<?php
/**
 * Read-only gallery repository that substitutes one unsaved gallery state for the authorised preview.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value objects use camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Frontend;

use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryPage;
use SWGallery\Core\Domain\Gallery\GalleryQuery;
use SWGallery\Core\Domain\Gallery\NewGallery;

/**
 * `find()` returns the previewed gallery (stored identity and status, unsaved layout, sort mode and settings) for its
 * id; reads of other ids pass through. Every write throws: a preview never persists anything.
 */
final class PreviewGalleries implements GalleryRepositoryInterface {

	public function __construct(
		private readonly GalleryRepositoryInterface $inner,
		private readonly Gallery $previewed
	) {
	}

	public function find( int $id ): ?Gallery {
		return $id === $this->previewed->id ? $this->previewed : $this->inner->find( $id );
	}

	public function search( GalleryQuery $query ): GalleryPage {
		return $this->inner->search( $query );
	}

	public function create( NewGallery $new_gallery ): Gallery {
		throw $this->read_only();
	}

	public function update( Gallery $gallery ): void {
		throw $this->read_only();
	}

	public function duplicate( int $sourceId, string $title, int $ownerId ): Gallery {
		throw $this->read_only();
	}

	public function trash( int $id ): void {
		throw $this->read_only();
	}

	public function restore( int $id ): Gallery {
		throw $this->read_only();
	}

	public function delete( int $id ): void {
		throw $this->read_only();
	}

	private function read_only(): \LogicException {
		return new \LogicException( 'The preview repository is read-only.' );
	}
}
