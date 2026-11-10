<?php
/**
 * Gallery definitions stored as `swg_gallery` posts.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- the framework independent core's value objects expose camelCase properties (CODE-STYLE 3.1).

namespace SWGallery\WordPress\Repository;

use SWGallery\Core\Contract\GalleryRepositoryInterface;
use SWGallery\Core\Domain\Gallery\Gallery;
use SWGallery\Core\Domain\Gallery\GalleryPage;
use SWGallery\Core\Domain\Gallery\GalleryQuery;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Core\Domain\Gallery\SortMode;
use SWGallery\Core\Exception\GalleryNotFoundException;
use SWGallery\WordPress\PostTypes;

/**
 * Mapping: title = post title, description = post content (plain text, decision F-10), owner = post author, cover =
 * featured image, status = post status (a scheduled `future` status needs a publication date and is therefore only
 * kept, never created), passwordProtected = post password. Layout and settings live in the registered meta keys.
 * Authorization is not decided here (see the access policy); no method touches an attachment.
 */
final class WpGalleryRepository implements GalleryRepositoryInterface {

	private const THUMBNAIL_META = '_thumbnail_id';

	/** Statuses listed when a query names none (everything except the trash). */
	private const LIVE_STATUSES = array( 'publish', 'private', 'draft', 'pending', 'future' );

	private ?bool $trash_enabled;

	/**
	 * @param WpdbGalleryRelations $relations     Item membership operations.
	 * @param bool|null            $trash_enabled Whether WordPress keeps trashed posts (EMPTY_TRASH_DAYS > 0); null reads the constant.
	 */
	public function __construct( private readonly WpdbGalleryRelations $relations, ?bool $trash_enabled = null ) {
		$this->trash_enabled = $trash_enabled;
	}

	public function find( int $id ): ?Gallery {
		$post = $this->post( $id );
		return null === $post ? null : $this->hydrate( $post );
	}

	public function search( GalleryQuery $query ): GalleryPage {
		$statuses = array() === $query->statuses
			? self::LIVE_STATUSES
			: array_map( static fn( GalleryStatus $status ): string => $status->value, $query->statuses );

		$args = array(
			'post_type'              => PostTypes::GALLERY,
			'post_status'            => $statuses,
			'posts_per_page'         => $query->limit,
			'offset'                 => $query->offset,
			'orderby'                => array(
				'modified' => 'DESC',
				'ID'       => 'DESC',
			),
			'no_found_rows'          => false,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'cache_results'          => true,
		);
		if ( null !== $query->ownerId ) {
			// author__in also matches owner 0; `author` would ignore it.
			$args['author__in'] = array( $query->ownerId );
		}
		$search = null === $query->search ? '' : trim( $query->search );
		if ( '' !== $search ) {
			$args['s']              = $search;
			$args['sentence']       = true;
			$args['search_columns'] = array( 'post_title', 'post_content' );
		}

		$wp_query  = new \WP_Query( $args );
		$galleries = array();
		foreach ( (array) $wp_query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$galleries[] = $this->hydrate( $post );
			}
		}
		return new GalleryPage( $galleries, max( count( $galleries ), (int) $wp_query->found_posts ) );
	}

	public function create( NewGallery $new_gallery ): Gallery {
		if ( GalleryStatus::Future === $new_gallery->status ) {
			throw new \InvalidArgumentException( 'A scheduled gallery needs a publication date, which galleries do not have yet.' );
		}
		$id = $this->insert( $new_gallery->title, $new_gallery->description, $new_gallery->status, $new_gallery->ownerId );
		$this->write_settings( $id, $new_gallery->settings );
		return $this->require_gallery( $id );
	}

	public function update( Gallery $gallery ): void {
		$post = $this->post( $gallery->id ) ?? throw new GalleryNotFoundException();
		if ( GalleryStatus::Trash === $gallery->status ) {
			throw new \InvalidArgumentException( 'Use trash() to move a gallery to the trash.' );
		}
		if ( 'trash' === $post->post_status ) {
			throw new \InvalidArgumentException( 'Restore the gallery before updating it.' );
		}
		if ( GalleryStatus::Future === $gallery->status && 'future' !== $post->post_status ) {
			throw new \InvalidArgumentException( 'A scheduled gallery needs a publication date, which galleries do not have yet.' );
		}

		$result = $this->plain_text_storage(
			static fn() => wp_update_post(
				wp_slash(
					array(
						'ID'           => $gallery->id,
						'post_title'   => self::clean_title( $gallery->title ),
						'post_content' => self::clean_description( $gallery->description ),
						'post_status'  => $gallery->status->value,
						'post_author'  => $gallery->ownerId,
					)
				),
				true
			)
		);
		if ( $result instanceof \WP_Error || ! is_int( $result ) || $result <= 0 ) {
			throw new \RuntimeException( 'The gallery could not be stored.' );
		}
		$this->write_settings( $gallery->id, $gallery->settings );
		$this->write_cover( $gallery->id, $gallery->coverAttachmentId );
	}

	public function duplicate( int $sourceId, string $title, int $ownerId ): Gallery {
		$source  = $this->post( $sourceId ) ?? throw new GalleryNotFoundException();
		$gallery = $this->hydrate( $source );

		$copy_id = $this->insert( $title, $gallery->description, GalleryStatus::Draft, $ownerId );
		try {
			$this->write_settings( $copy_id, $gallery->settings );
			$this->write_cover( $copy_id, $gallery->coverAttachmentId );
			$this->relations->copy_membership( $sourceId, $copy_id );
		} catch ( \Throwable $e ) {
			// Never leave a half-copied gallery behind; the source stays untouched.
			wp_delete_post( $copy_id, true );
			$this->relations->delete_by_gallery( $copy_id );
			throw $e;
		}
		return $this->require_gallery( $copy_id );
	}

	public function trash( int $id ): void {
		$post = $this->post( $id ) ?? throw new GalleryNotFoundException();
		if ( 'trash' === $post->post_status ) {
			return;
		}

		if ( $this->trash_enabled() ) {
			if ( ! wp_trash_post( $id ) ) {
				throw new \RuntimeException( 'The gallery could not be moved to the trash.' );
			}
			return;
		}

		// wp_trash_post() would delete permanently when the trash is disabled; galleries must stay restorable
		// (relations are kept), so the same state is written by hand.
		update_post_meta( $id, '_wp_trash_meta_status', $post->post_status );
		update_post_meta( $id, '_wp_trash_meta_time', time() );
		$this->set_post_status( $id, 'trash' );
	}

	public function restore( int $id ): Gallery {
		$post = $this->post( $id ) ?? throw new GalleryNotFoundException();
		if ( 'trash' !== $post->post_status ) {
			return $this->hydrate( $post );
		}

		if ( ! wp_untrash_post( $id ) instanceof \WP_Post ) {
			throw new \RuntimeException( 'The gallery could not be restored.' );
		}
		// A restored gallery is always a draft, whatever other code filtered the previous status to.
		if ( 'draft' !== get_post_status( $id ) ) {
			$this->set_post_status( $id, 'draft' );
		}
		return $this->require_gallery( $id );
	}

	public function delete( int $id ): void {
		if ( null === $this->post( $id ) ) {
			throw new GalleryNotFoundException();
		}
		// Fires before_delete_post, where the lifecycle registrar removes the relations as well (idempotent).
		if ( ! wp_delete_post( $id, true ) ) {
			throw new \RuntimeException( 'The gallery could not be deleted.' );
		}
		$this->relations->delete_by_gallery( $id );
	}

	/**
	 * @return \WP_Post|null The gallery post, or null when the id is no gallery.
	 */
	private function post( int $id ): ?\WP_Post {
		if ( $id <= 0 ) {
			return null;
		}
		$post = get_post( $id );
		return ( $post instanceof \WP_Post && PostTypes::GALLERY === $post->post_type ) ? $post : null;
	}

	private function require_gallery( int $id ): Gallery {
		return $this->find( $id ) ?? throw new GalleryNotFoundException();
	}

	private function trash_enabled(): bool {
		return $this->trash_enabled ?? ( ! defined( 'EMPTY_TRASH_DAYS' ) || (int) EMPTY_TRASH_DAYS > 0 );
	}

	private function insert( string $title, string $description, GalleryStatus $status, int $owner_id ): int {
		$id = $this->plain_text_storage(
			static fn() => wp_insert_post(
				wp_slash(
					array(
						'post_type'    => PostTypes::GALLERY,
						'post_title'   => self::clean_title( $title ),
						'post_content' => self::clean_description( $description ),
						'post_status'  => $status->value,
						'post_author'  => $owner_id,
					)
				),
				true
			)
		);
		if ( $id instanceof \WP_Error || ! is_int( $id ) || $id <= 0 ) {
			throw new \RuntimeException( 'The gallery could not be created.' );
		}
		return $id;
	}

	private function set_post_status( int $id, string $status ): void {
		$result = $this->plain_text_storage(
			static fn() => wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => $status,
				),
				true
			)
		);
		if ( $result instanceof \WP_Error || ! is_int( $result ) || $result <= 0 ) {
			throw new \RuntimeException( 'The gallery status could not be changed.' );
		}
	}

	/** Plain text with "\n" line breaks (decision F-10), whatever line endings the client sent. */
	private static function clean_description( string $description ): string {
		return sanitize_textarea_field( str_replace( array( "\r\n", "\r" ), "\n", $description ) );
	}

	private static function clean_title( string $title ): string {
		return sanitize_text_field( $title );
	}

	/**
	 * Runs a post write without the kses filters WordPress adds for users without `unfiltered_html`: title and
	 * description are plain text that is escaped on output, kses would store "&" as "&amp;" and corrupt it.
	 * Input is sanitized explicitly before (sanitize_text_field / sanitize_textarea_field).
	 *
	 * @param callable(): mixed $write The write operation.
	 * @return mixed
	 */
	private function plain_text_storage( callable $write ) {
		kses_remove_filters();
		try {
			return $write();
		} finally {
			kses_init();
		}
	}

	private function write_settings( int $id, GallerySettings $settings ): void {
		$json = wp_json_encode( (object) $settings->values );
		if ( false === $json ) {
			throw new \InvalidArgumentException( 'The gallery settings cannot be encoded as JSON.' );
		}
		update_post_meta( $id, PostTypes::META_LAYOUT, wp_slash( $settings->layout ) );
		update_post_meta( $id, PostTypes::META_SORT_MODE, wp_slash( $settings->sortMode->value ) );
		update_post_meta( $id, PostTypes::META_SETTINGS, wp_slash( $json ) );
		update_post_meta( $id, PostTypes::META_SCHEMA_VERSION, $settings->schemaVersion );
	}

	private function write_cover( int $id, ?int $attachment_id ): void {
		// Direct meta instead of set_post_thumbnail(): that rejects ids that are not (yet) image attachments, and
		// whether an id may be used is decided by the access policy, not by the storage.
		if ( null === $attachment_id ) {
			delete_post_meta( $id, self::THUMBNAIL_META );
			return;
		}
		update_post_meta( $id, self::THUMBNAIL_META, $attachment_id );
	}

	private function hydrate( \WP_Post $post ): Gallery {
		$cover = (int) get_post_meta( $post->ID, self::THUMBNAIL_META, true );
		return new Gallery(
			$post->ID,
			$post->post_title,
			$post->post_content,
			GalleryStatus::tryFrom( $post->post_status ) ?? GalleryStatus::Draft,
			(int) $post->post_author,
			$cover > 0 ? $cover : null,
			$this->read_settings( $post->ID ),
			$this->modified_at( $post ),
			'' !== $post->post_password
		);
	}

	private function read_settings( int $id ): GallerySettings {
		$layout = (string) get_post_meta( $id, PostTypes::META_LAYOUT, true );
		if ( 1 !== preg_match( GallerySettings::LAYOUT_ID_PATTERN, $layout ) ) {
			$layout = GallerySettings::DEFAULT_LAYOUT;
		}
		$decoded = json_decode( (string) get_post_meta( $id, PostTypes::META_SETTINGS, true ), true );
		$values  = array();
		foreach ( is_array( $decoded ) ? $decoded : array() as $key => $value ) {
			$values[ (string) $key ] = $value;
		}
		return new GallerySettings(
			$layout,
			SortMode::tryFrom( (string) get_post_meta( $id, PostTypes::META_SORT_MODE, true ) ) ?? SortMode::Manual,
			$values,
			max( 1, (int) get_post_meta( $id, PostTypes::META_SCHEMA_VERSION, true ) )
		);
	}

	private function modified_at( \WP_Post $post ): \DateTimeImmutable {
		$utc = new \DateTimeZone( 'UTC' );
		// WordPress stores "0000-00-00 00:00:00" for "unknown", which PHP would turn into a date in year -1.
		$date = str_starts_with( $post->post_modified_gmt, '0000' ) ? false : \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $post->post_modified_gmt, $utc );
		return false === $date ? new \DateTimeImmutable( 'now', $utc ) : $date;
	}
}
