<?php
/**
 * Media Library integration (M2-38): "Used in n galleries" in the attachment details and in the list view, plus a
 * confirmation before an attachment that galleries use is deleted permanently.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Admin;

use SWGallery\WordPress\Bootstrap\RegistrarInterface;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;

/**
 * Narrow and read-only:
 * - Attachment details (`attachment_fields_to_edit`): the number of galleries using the image and links to the ones
 *   the current user may edit (at most {@see self::MAX_LISTED}, then "and n more"). Users who may edit none see only
 *   the count. The count itself is aggregate data, not a gallery title.
 * - List view column "Galleries" (`manage_media_columns`): the count of every visible row from ONE grouped query for the
 *   whole page.
 * - A tiny classic script (`assets/media-library`) asks `window.confirm` before an attachment with `data-swg-used > 0`
 *   is deleted permanently. It is enqueued only on `upload.php` and the attachment edit screen. Nothing here changes the
 *   behaviour of the lifecycle hook that removes the relations when the attachment is really deleted.
 */
final class MediaLibraryRegistrar implements RegistrarInterface {

	public const COLUMN       = 'swg_galleries';
	public const SCRIPT       = 'swg-media-library';
	public const MAX_LISTED   = 10;
	private const USAGE_IDS   = 50;
	private const SCRIPT_FILE = 'media-library.js';

	private WpdbGalleryRelations $relations;
	private string $assets_dir;
	private string $assets_url;

	/** @var array<int, int>|null Attachment id => number of galleries, loaded once for the visible list page. */
	private ?array $page_counts = null;

	/**
	 * @param WpdbGalleryRelations|null $relations  Relation reader.
	 * @param string|null               $assets_dir Directory of the script; defaults to assets/media-library.
	 * @param string|null               $assets_url Public URL of that directory.
	 */
	public function __construct( ?WpdbGalleryRelations $relations = null, ?string $assets_dir = null, ?string $assets_url = null ) {
		$this->relations  = $relations ?? new WpdbGalleryRelations();
		$this->assets_dir = rtrim( $assets_dir ?? SWGALLERY_DIR . 'assets/media-library', '/\\' );
		$this->assets_url = rtrim( $assets_url ?? SWGALLERY_URL . 'assets/media-library', '/' );
	}

	public function register(): void {
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_field' ), 10, 2 );
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Filter `attachment_fields_to_edit`.
	 *
	 * @param mixed $fields Form fields of the attachment.
	 * @param mixed $post   The attachment.
	 * @return mixed
	 */
	public function add_field( $fields, $post ) {
		if ( ! is_array( $fields ) || ! $post instanceof \WP_Post || ! $this->applies_to( $post ) ) {
			return $fields;
		}
		$counts = $this->relations->count_galleries_by_attachment( array( $post->ID ) );
		$count  = $counts[ $post->ID ] ?? 0;

		$fields[ self::COLUMN ] = array(
			'label' => __( 'Used in galleries', 'sw-gallery' ),
			'input' => 'html',
			'html'  => $this->field_html( $post->ID, $count ),
		);
		return $fields;
	}

	/**
	 * @param mixed $columns List view columns.
	 * @return mixed
	 */
	public function add_column( $columns ) {
		if ( is_array( $columns ) && current_user_can( 'upload_files' ) ) {
			$columns[ self::COLUMN ] = __( 'Galleries', 'sw-gallery' );
		}
		return $columns;
	}

	/**
	 * Action `manage_media_custom_column`.
	 *
	 * @param mixed $column  Column id.
	 * @param mixed $post_id Attachment id.
	 */
	public function render_column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$id    = (int) $post_id;
		$count = $this->count_for_list_row( $id );
		printf(
			'<span class="swg-used-count" data-swg-used="%d">%s</span>',
			(int) $count,
			$count > 0 ? esc_html( number_format_i18n( $count ) ) : '&mdash;'
		);
	}

	/**
	 * One grouped query for all attachments of the visible list page (the main query holds exactly that page).
	 */
	private function count_for_list_row( int $id ): int {
		if ( null === $this->page_counts || ! array_key_exists( $id, $this->page_counts ) ) {
			$ids = array( $id );
			if ( null === $this->page_counts ) {
				$posts = $GLOBALS['wp_query']->posts ?? array();
				foreach ( is_array( $posts ) ? $posts : array() as $post ) {
					if ( $post instanceof \WP_Post ) {
						$ids[] = $post->ID;
					}
				}
			}
			$this->page_counts = ( $this->page_counts ?? array() ) + $this->relations->count_galleries_by_attachment( array_values( array_unique( $ids ) ) );
		}
		return $this->page_counts[ $id ] ?? 0;
	}

	/** Only image attachments can be used in galleries. */
	private function applies_to( \WP_Post $post ): bool {
		return 'attachment' === $post->post_type && str_starts_with( (string) $post->post_mime_type, 'image/' ) && current_user_can( 'upload_files' );
	}

	private function field_html( int $attachment_id, int $count ): string {
		if ( 0 === $count ) {
			return '<span class="swg-used-in" data-swg-used="0">' . esc_html__( 'Not used in any gallery.', 'sw-gallery' ) . '</span>';
		}
		$summary = sprintf(
			/* translators: %d: number of galleries that use this image */
			_n( 'Used in %d gallery', 'Used in %d galleries', $count, 'sw-gallery' ),
			$count
		);
		$links = $this->editable_links( $attachment_id );
		$html  = '<span class="swg-used-in" data-swg-used="' . (int) $count . '">' . esc_html( $summary );
		if ( array() !== $links ) {
			$html .= ': ' . implode( ', ', $links );
			$more  = $count - count( $links );
			if ( $more > 0 ) {
				$html .= ' ' . esc_html(
					sprintf(
						/* translators: %d: number of further galleries that are not listed */
						__( 'and %d more', 'sw-gallery' ),
						$more
					)
				);
			}
		}
		return $html . '</span>';
	}

	/**
	 * Links to live galleries (not trashed) that the current user may edit, at most MAX_LISTED. Titles are escaped.
	 *
	 * @return list<string> HTML links.
	 */
	private function editable_links( int $attachment_id ): array {
		$ids = $this->relations->gallery_ids_with_attachment( $attachment_id, self::USAGE_IDS );
		_prime_post_caches( $ids, false, false );
		$links = array();
		foreach ( $ids as $gallery_id ) {
			$gallery = get_post( $gallery_id );
			if ( ! $gallery instanceof \WP_Post || PostTypes::GALLERY !== $gallery->post_type || 'trash' === $gallery->post_status || ! current_user_can( 'edit_post', $gallery_id ) ) {
				continue;
			}
			$title   = '' === $gallery->post_title ? __( '(no title)', 'sw-gallery' ) : $gallery->post_title;
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . AdminRegistrar::PAGE_SLUG . '#/galleries/' . $gallery_id ) ),
				esc_html( $title )
			);
			if ( count( $links ) >= self::MAX_LISTED ) {
				break;
			}
		}
		return $links;
	}

	/**
	 * Loads the confirmation script on the Media Library list and the attachment edit screen only.
	 *
	 * @param string $hook_suffix Hook suffix of the screen being loaded.
	 */
	public function enqueue( $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || ! in_array( $screen->id, array( 'upload', 'attachment' ), true ) || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$file = $this->assets_dir . '/' . self::SCRIPT_FILE;
		if ( ! is_file( $file ) ) {
			return;
		}
		wp_enqueue_script( self::SCRIPT, $this->assets_url . '/' . self::SCRIPT_FILE, array(), SWGALLERY_VERSION, true );
		wp_add_inline_script( self::SCRIPT, 'window.swgMediaLibrary = ' . wp_json_encode( $this->messages() ) . ';', 'before' );
	}

	/**
	 * Strings of the script; `%d` is replaced by the script.
	 *
	 * @return array<string, string>
	 */
	public function messages(): array {
		return array(
			/* translators: %d: number of galleries (one) */
			'singleOne'  => __( 'This image is used in %d gallery. If you delete it permanently, it is removed from that gallery. Delete it anyway?', 'sw-gallery' ),
			/* translators: %d: number of galleries */
			'singleMany' => __( 'This image is used in %d galleries. If you delete it permanently, it is removed from those galleries. Delete it anyway?', 'sw-gallery' ),
			/* translators: %d: selected images that galleries use (one) */
			'bulkOne'    => __( '%d of the selected images is used in galleries. If you delete them permanently, they are removed from those galleries. Delete them anyway?', 'sw-gallery' ),
			/* translators: %d: selected images that galleries use */
			'bulkMany'   => __( '%d of the selected images are used in galleries. If you delete them permanently, they are removed from those galleries. Delete them anyway?', 'sw-gallery' ),
		);
	}
}
