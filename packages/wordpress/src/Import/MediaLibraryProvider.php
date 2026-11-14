<?php
/**
 * Import provider for the native WordPress Media Library (flat listing, no copying).
 *
 * "Importing" from this provider resolves to the EXISTING attachment: files are never copied or deleted.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Import;

use SWGallery\Core\Contract\MediaImportProviderInterface;
use SWGallery\Core\Contract\RemoteStreamInterface;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Import\FolderPage;
use SWGallery\Core\Domain\Import\ImportCapabilities;
use SWGallery\Core\Domain\Import\ImportConnection;
use SWGallery\Core\Domain\Import\RemoteMediaObject;
use SWGallery\Core\Domain\Import\RemoteMediaPage;
use SWGallery\Core\Exception\ImportProviderException;
use SWGallery\WordPress\Security\WpGalleryAccessPolicy;

/**
 * Visibility follows decision D11: a user may use every image attachment they can read
 * ({@see WpGalleryAccessPolicy::canUseAttachment()}: allowed type, not trashed, `edit_posts`, `read_post`), the same
 * rule that the item services apply when an image is added to a gallery. Changing attachment metadata is not part of
 * this provider.
 */
final class MediaLibraryProvider implements MediaImportProviderInterface {

	public const PROVIDER_ID = 'media-library';

	private const ERR_FORBIDDEN   = 'swg_import_forbidden';
	private const ERR_NOT_FOUND   = 'swg_import_not_found';
	private const ERR_BAD_CURSOR  = 'swg_import_invalid_cursor';
	private const ERR_FOLDERS     = 'swg_import_folders_unsupported';
	private const ERR_PROVIDER    = 'swg_import_wrong_provider';
	private const ERR_UNAVAILABLE = 'swg_import_file_unavailable';

	/** Ids and cursors are plain positive decimal integers (attachment IDs). */
	private const ID_PATTERN = '/^[1-9][0-9]{0,17}$/';

	private WpGalleryAccessPolicy $access;

	public function __construct( ?WpGalleryAccessPolicy $access = null ) {
		$this->access = $access ?? new WpGalleryAccessPolicy();
	}

	public function getProviderId(): string {
		return self::PROVIDER_ID;
	}

	public function getCapabilities(): ImportCapabilities {
		// Flat library listing only: no folders, no recursion, no revision or content hash tracking.
		return new ImportCapabilities( false, false, false, false );
	}

	public function listFolders(
		ImportConnection $connection,
		?string $parentFolderId,
		?string $cursor,
		int $limit
	): FolderPage {
		$this->assertConnection( $connection );
		return new FolderPage( array() );
	}

	public function listMedia(
		ImportConnection $connection,
		?string $folderId,
		?string $cursor,
		int $limit
	): RemoteMediaPage {
		$this->assertConnection( $connection );
		if ( null !== $folderId ) {
			throw new ImportProviderException( self::ERR_FOLDERS, false );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new ImportProviderException( self::ERR_FORBIDDEN, false );
		}
		$after = null === $cursor ? 0 : $this->parseId( $cursor, self::ERR_BAD_CURSOR );
		$limit = max( 1, min( self::MAX_PAGE_SIZE, $limit ) );

		$args  = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'posts_per_page'         => $limit + 1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		);
		$where = static function ( string $clause ) use ( $after ): string {
			global $wpdb;
			return $clause . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name.
		};
		add_filter( 'posts_where', $where );
		try {
			$query = new \WP_Query( $args );
		} finally {
			remove_filter( 'posts_where', $where );
		}

		$posts = array();
		foreach ( $query->posts ?? array() as $post ) {
			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}
		$more  = count( $posts ) > $limit;
		$posts = array_slice( $posts, 0, $limit );

		// The page boundary (and cursor) is computed before the policy filter, so a page may hold fewer objects.
		$objects = array();
		foreach ( $posts as $post ) {
			if ( $this->usable( $post->ID ) ) {
				$objects[] = $this->describe( $post );
			}
		}
		$next = ( $more && array() !== $posts ) ? (string) $posts[ count( $posts ) - 1 ]->ID : null;

		return new RemoteMediaPage( $objects, $next );
	}

	public function getMediaMetadata( ImportConnection $connection, string $remoteObjectId ): RemoteMediaObject {
		$this->assertConnection( $connection );
		return $this->describe( $this->readableImage( $remoteObjectId ) );
	}

	public function openDownloadStream( ImportConnection $connection, string $remoteObjectId ): RemoteStreamInterface {
		$this->assertConnection( $connection );
		$post = $this->readableImage( $remoteObjectId );
		$path = $this->localPath( $post->ID );
		if ( null === $path ) {
			throw new ImportProviderException( self::ERR_UNAVAILABLE, false );
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- bounded local read of a validated upload path.
		if ( false === $handle ) {
			throw new ImportProviderException( self::ERR_UNAVAILABLE, false );
		}
		return new LocalFileStream( $handle );
	}

	private function assertConnection( ImportConnection $connection ): void {
		if ( self::PROVIDER_ID !== $connection->providerId ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- core value object uses camelCase.
			throw new ImportProviderException( self::ERR_PROVIDER, false );
		}
	}

	/** @throws ImportProviderException Permanent not-found for malformed, missing, non-image or unreadable ids (no existence leak). */
	private function readableImage( string $remoteObjectId ): \WP_Post {
		$id   = $this->parseId( $remoteObjectId, self::ERR_NOT_FOUND );
		$post = get_post( $id );
		if (
			! $post instanceof \WP_Post
			|| 'attachment' !== $post->post_type
			|| ! str_starts_with( (string) get_post_mime_type( $post ), 'image/' )
			|| ! $this->usable( $id )
		) {
			throw new ImportProviderException( self::ERR_NOT_FOUND, false );
		}
		return $post;
	}

	private function usable( int $attachmentId ): bool {
		return $this->access->canUseAttachment( new Actor( get_current_user_id() ), $attachmentId );
	}

	private function parseId( string $value, string $errorCode ): int {
		if ( 1 !== preg_match( self::ID_PATTERN, $value ) ) {
			throw new ImportProviderException( $errorCode, false );
		}
		return (int) $value;
	}

	/** Returns the real path of the attachment file only if it lies inside the uploads directory. */
	private function localPath( int $attachmentId ): ?string {
		$file = get_attached_file( $attachmentId );
		if ( ! is_string( $file ) || '' === $file || str_contains( $file, "\0" ) || str_contains( $file, '://' ) ) {
			return null;
		}
		$uploads = wp_upload_dir( null, false );
		$base    = realpath( $uploads['basedir'] );
		$real    = realpath( $file );
		if ( false === $base || false === $real ) {
			return null;
		}
		if ( ! str_starts_with( $real, rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR ) || ! is_file( $real ) || ! is_readable( $real ) ) {
			return null;
		}
		return $real;
	}

	/** Attachment metadata is loosely typed third-party data; accept only positive integers. */
	private function positiveInt( mixed $value ): ?int {
		return is_int( $value ) && $value > 0 ? $value : null;
	}

	private function describe( \WP_Post $post ): RemoteMediaObject {
		$meta = wp_get_attachment_metadata( $post->ID );
		$meta = is_array( $meta ) ? $meta : array();

		$size = $this->positiveInt( $meta['filesize'] ?? null );
		if ( null === $size ) {
			$path = $this->localPath( $post->ID );
			$size = null === $path ? 0 : (int) filesize( $path );
		}
		$width  = $this->positiveInt( $meta['width'] ?? null );
		$height = $this->positiveInt( $meta['height'] ?? null );

		$file = get_post_meta( $post->ID, '_wp_attached_file', true );
		$name = is_string( $file ) && '' !== $file ? wp_basename( $file ) : 'attachment-' . $post->ID;

		$modified = null;
		if ( '' !== $post->post_modified_gmt && '0000-00-00 00:00:00' !== $post->post_modified_gmt ) {
			$date     = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $post->post_modified_gmt, new \DateTimeZone( 'UTC' ) );
			$modified = false === $date ? null : $date;
		}

		return new RemoteMediaObject(
			(string) $post->ID,
			$name,
			(string) get_post_mime_type( $post ),
			max( 0, $size ),
			null,
			null,
			$modified,
			null,
			$width,
			$height
		);
	}
}
