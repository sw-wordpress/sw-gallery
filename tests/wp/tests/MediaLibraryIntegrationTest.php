<?php
/**
 * M2-38: "used in n galleries" in the attachment details and the list view column, the confirmation script on the
 * two screens that need it, and the unchanged deletion behaviour.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Tests\Support\WpFixtures;
use SWGallery\WordPress\Admin\MediaLibraryRegistrar;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;
use SWGallery\WordPress\Repository\WpdbGalleryRelations;
use SWGallery\WordPress\Repository\WpGalleryRepository;

class MediaLibraryIntegrationTest extends SwgTestCase {

	use WpFixtures;

	private WpGalleryRepository $repository;
	private WpdbGalleryItemRepository $items;
	private MediaLibraryRegistrar $registrar;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		$this->repository = new WpGalleryRepository( new WpdbGalleryRelations() );
		$this->items      = new WpdbGalleryItemRepository();
		$this->registrar  = new MediaLibraryRegistrar( null, SWGALLERY_DIR . 'assets/media-library', 'https://example.test/ml' );
		wp_dequeue_script( MediaLibraryRegistrar::SCRIPT );
		wp_deregister_script( MediaLibraryRegistrar::SCRIPT );
	}

	public function tear_down(): void {
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		wp_dequeue_script( MediaLibraryRegistrar::SCRIPT );
		wp_deregister_script( MediaLibraryRegistrar::SCRIPT );
		$this->purgeGalleries();
		parent::tear_down();
	}

	private function gallery( string $title, int $owner, int ...$attachments ): int {
		$gallery = $this->repository->create( new NewGallery( $title, $owner, '', GalleryStatus::Publish ) );
		foreach ( $attachments as $attachment ) {
			$this->items->append( $gallery->id, $attachment );
		}
		return $gallery->id;
	}

	private function field_html( int $attachment ): string {
		$fields = $this->registrar->add_field( array(), get_post( $attachment ) );
		return isset( $fields[ MediaLibraryRegistrar::COLUMN ] ) ? (string) $fields[ MediaLibraryRegistrar::COLUMN ]['html'] : '';
	}

	public function test_the_registrar_hooks_the_four_extension_points(): void {
		$this->registrar->register();
		$this->assertNotFalse( has_filter( 'attachment_fields_to_edit', array( $this->registrar, 'add_field' ) ) );
		$this->assertNotFalse( has_filter( 'manage_media_columns', array( $this->registrar, 'add_column' ) ) );
		$this->assertNotFalse( has_action( 'manage_media_custom_column', array( $this->registrar, 'render_column' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $this->registrar, 'enqueue' ) ) );
	}

	public function test_the_field_lists_editable_galleries_and_counts_only_live_ones(): void {
		$editor = $this->makeUser( 'editor' );
		wp_set_current_user( $editor );
		$image = $this->makeAttachment( 0, 'image/jpeg' );
		$one   = $this->gallery( 'First', $editor, $image );
		$two   = $this->gallery( 'Second', $editor, $image );
		$trash = $this->gallery( 'Trashed', $editor, $image );
		$this->repository->trash( $trash );

		$html = $this->field_html( $image );

		$this->assertStringContainsString( 'Used in 2 galleries', $html );
		$this->assertStringContainsString( 'data-swg-used="2"', $html );
		$this->assertStringContainsString( '#/galleries/' . $one . '"', $html );
		$this->assertStringContainsString( '#/galleries/' . $two . '"', $html );
		$this->assertStringNotContainsString( 'Trashed', $html );
		$this->assertStringNotContainsString( 'and ', str_replace( 'Used in 2 galleries', '', $html ), 'Nothing is hidden, so no "and n more".' );
	}

	public function test_attachment_details_keep_gallery_links_on_the_edit_screen_and_in_ajax(): void {
		global $pagenow;
		$editor = $this->makeUser( 'editor' );
		wp_set_current_user( $editor );
		$image   = $this->makeAttachment( $editor, 'image/jpeg' );
		$gallery = $this->gallery( 'Linked gallery', $editor, $image );
		$link    = '#/galleries/' . $gallery . '"';

		$previous = $pagenow;
		$pagenow  = 'post.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate the attachment edit screen.
		set_current_screen( 'attachment' );
		try {
			$this->assertStringContainsString( $link, $this->field_html( $image ) );
		} finally {
			$pagenow = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the native request context.
			set_current_screen( 'front' );
		}

		add_filter( 'wp_doing_ajax', '__return_true' );
		try {
			$this->assertStringContainsString( $link, $this->field_html( $image ) );
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
	}

	public function test_a_user_who_may_edit_none_of_the_galleries_sees_only_the_count(): void {
		$admin = $this->makeUser( 'administrator' );
		$image = $this->makeAttachment( 0, 'image/jpeg' );
		$this->gallery( 'Secret title', $admin, $image );
		$this->gallery( 'Another secret', $admin, $image );
		// Authors may upload and see the details, but may not edit the administrator's galleries.
		wp_set_current_user( $this->makeUser( 'author' ) );

		$html = $this->field_html( $image );

		$this->assertStringContainsString( 'Used in 2 galleries', $html );
		$this->assertStringNotContainsString( 'secret', strtolower( $html ) );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( 'swg-galleries', $html );
	}

	public function test_at_most_ten_galleries_are_listed_followed_by_and_n_more(): void {
		$editor = $this->makeUser( 'editor' );
		wp_set_current_user( $editor );
		$image = $this->makeAttachment( 0, 'image/jpeg' );
		for ( $i = 1; $i <= 13; ++$i ) {
			$this->gallery( 'G' . $i, $editor, $image );
		}

		$html = $this->field_html( $image );

		$this->assertStringContainsString( 'Used in 13 galleries', $html );
		$this->assertSame( 10, substr_count( $html, '<a ' ) );
		$this->assertStringContainsString( 'and 3 more', $html );
	}

	public function test_titles_are_escaped_and_unused_images_say_so(): void {
		$editor = $this->makeUser( 'editor' );
		wp_set_current_user( $editor );
		$image = $this->makeAttachment( 0, 'image/jpeg' );
		$this->assertStringContainsString( 'Not used in any gallery', $this->field_html( $image ) );
		$this->assertStringContainsString( 'data-swg-used="0"', $this->field_html( $image ) );

		global $wpdb;
		$id = $this->gallery( 'Plain', $editor, $image );
		// Titles are sanitised on write; a hostile one that got into the database anyway must still be escaped.
		$wpdb->update( $wpdb->posts, array( 'post_title' => '<b>Bold</b> & "x"' ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );
		$html = $this->field_html( $image );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;Bold&lt;/b&gt;', $html );
	}

	public function test_non_images_users_without_upload_rights_and_foreign_arguments_are_left_alone(): void {
		wp_set_current_user( $this->makeUser( 'editor' ) );
		$pdf = $this->makeAttachment( 0, 'application/pdf' );
		$this->assertSame( '', $this->field_html( $pdf ) );
		$this->assertSame( array( 'x' => 1 ), $this->registrar->add_field( array( 'x' => 1 ), new WP_Post( (object) array( 'ID' => 5, 'post_type' => 'post' ) ) ) );
		$this->assertSame( 'not-an-array', $this->registrar->add_field( 'not-an-array', get_post( $pdf ) ) );

		wp_set_current_user( $this->makeUser( 'subscriber' ) );
		$image = $this->makeAttachment( 0, 'image/jpeg' );
		$this->assertSame( '', $this->field_html( $image ) );
		$this->assertSame( array( 'a' => 'b' ), $this->registrar->add_column( array( 'a' => 'b' ) ) );
	}

	public function test_the_list_column_is_added_and_uses_one_grouped_query_for_the_page(): void {
		global $wpdb;
		$editor = $this->makeUser( 'editor' );
		wp_set_current_user( $editor );
		$images = array();
		for ( $i = 0; $i < 20; ++$i ) {
			$images[] = $this->makeAttachment( 0, 'image/jpeg' );
		}
		$this->gallery( 'A', $editor, $images[0], $images[1] );
		$this->gallery( 'B', $editor, $images[1] );

		$columns = $this->registrar->add_column( array( 'title' => 'File' ) );
		$this->assertSame( 'Galleries', $columns[ MediaLibraryRegistrar::COLUMN ] );

		$GLOBALS['wp_query']        = new WP_Query();
		$GLOBALS['wp_query']->posts = array_map( 'get_post', $images );
		$before                     = $wpdb->num_queries;
		ob_start();
		foreach ( $images as $image ) {
			$this->registrar->render_column( MediaLibraryRegistrar::COLUMN, $image );
		}
		$output  = (string) ob_get_clean();
		$queries = $wpdb->num_queries - $before;

		$this->assertLessThanOrEqual( 1, $queries, 'One grouped query for the whole page, no N+1.' );
		$this->assertStringContainsString( '<span class="swg-used-count" data-swg-used="2">2</span>', $output );
		$this->assertStringContainsString( 'data-swg-used="1">1</span>', $output );
		$this->assertSame( 18, substr_count( $output, 'data-swg-used="0">&mdash;</span>' ) );

		ob_start();
		$this->registrar->render_column( 'other', $images[0] );
		$this->assertSame( '', (string) ob_get_clean(), 'Foreign columns are ignored.' );
		$GLOBALS['wp_query'] = new WP_Query();
	}

	public function test_an_attachment_outside_the_main_query_is_counted_with_its_own_query(): void {
		$editor = $this->makeUser( 'editor' );
		wp_set_current_user( $editor );
		$image = $this->makeAttachment( 0, 'image/jpeg' );
		$this->gallery( 'A', $editor, $image );
		$GLOBALS['wp_query'] = new WP_Query();

		ob_start();
		$this->registrar->render_column( MediaLibraryRegistrar::COLUMN, $image );
		$this->assertStringContainsString( 'data-swg-used="1"', (string) ob_get_clean() );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function screens(): array {
		return array(
			'media list'      => array( 'upload', true ),
			'attachment edit' => array( 'attachment', true ),
			'post editor'     => array( 'post', false ),
			'dashboard'       => array( 'dashboard', false ),
			'plugin page'     => array( 'toplevel_page_swg-galleries', false ),
		);
	}

	/**
	 * @dataProvider screens
	 */
	public function test_the_script_is_enqueued_only_on_the_two_screens_and_versioned( string $screen, bool $expected ): void {
		wp_set_current_user( $this->makeUser( 'author' ) );
		set_current_screen( $screen );

		$this->registrar->enqueue( 'x' );

		$this->assertSame( $expected, wp_script_is( MediaLibraryRegistrar::SCRIPT, 'enqueued' ) );
		if ( $expected ) {
			$script = wp_scripts()->registered[ MediaLibraryRegistrar::SCRIPT ];
			$this->assertSame( 'https://example.test/ml/media-library.js', $script->src );
			$this->assertSame( SWGALLERY_VERSION, $script->ver );
			$this->assertTrue( (bool) $script->extra['group'] ?? false, 'Loaded in the footer.' );
			$inline = (string) wp_scripts()->get_inline_script_data( MediaLibraryRegistrar::SCRIPT, 'before' );
			$this->assertStringContainsString( 'window.swgMediaLibrary', $inline );
			$this->assertStringContainsString( '%d galleries', $inline );
		}
	}

	public function test_nothing_is_enqueued_for_users_without_upload_rights_or_without_the_file(): void {
		set_current_screen( 'upload' );
		wp_set_current_user( $this->makeUser( 'subscriber' ) );
		$this->registrar->enqueue( 'x' );
		$this->assertFalse( wp_script_is( MediaLibraryRegistrar::SCRIPT, 'enqueued' ) );

		wp_set_current_user( $this->makeUser( 'author' ) );
		( new MediaLibraryRegistrar( null, sys_get_temp_dir() . '/swg-missing-ml', 'https://example.test/ml' ) )->enqueue( 'x' );
		$this->assertFalse( wp_script_is( MediaLibraryRegistrar::SCRIPT, 'enqueued' ) );
	}

	public function test_the_script_file_ships_with_the_plugin_and_has_no_dependencies(): void {
		$file = SWGALLERY_DIR . 'assets/media-library/media-library.js';
		$this->assertFileExists( $file );
		$code = (string) file_get_contents( $file );
		$this->assertStringContainsString( 'window.confirm', $code );
		$this->assertStringNotContainsString( 'XMLHttpRequest', $code );
		$this->assertStringNotContainsString( 'fetch(', $code );
		$this->assertStringNotContainsString( 'innerHTML', $code );
	}

	public function test_deleting_an_attachment_still_removes_the_relations_through_the_lifecycle_hook(): void {
		$editor = $this->makeUser( 'editor' );
		wp_set_current_user( $editor );
		$image   = $this->makeAttachment( 0, 'image/jpeg' );
		$gallery = $this->gallery( 'A', $editor, $image );
		$this->registrar->register();

		wp_delete_attachment( $image, true );

		$this->assertSame( array(), $this->items->assignedAttachmentIds( $gallery, array( $image ) ) );
		$this->assertNotNull( get_post( $gallery ), 'The gallery stays.' );
	}
}
