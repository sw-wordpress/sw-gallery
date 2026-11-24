<?php
/**
 * Native post writes respect the gallery service boundary.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Tests\Support\RestItemFixtures;
use SWGallery\WordPress\Bootstrap\CoreRegistrar;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\PostTypes;

class NativeGalleryWriteGuardTest extends SwgTestCase {

	use RestItemFixtures;

	public function test_core_registrar_installs_the_native_write_guards(): void {
		$registrar = new CoreRegistrar();
		$registrar->register();

		$this->assertSame( 10, has_action( 'init', array( $registrar, 'load_textdomain' ) ) );
		$this->assertTrue( has_filter( 'wp_insert_post_empty_content' ) );
		$this->assertTrue( has_filter( 'pre_trash_post' ) );
		$this->assertTrue( has_filter( 'pre_untrash_post' ) );
		$this->assertTrue( has_filter( 'pre_delete_post' ) );
	}

	public function test_native_ajax_refuses_gallery_saves_and_lifecycle_writes(): void {
		global $wp_current_filter;
		$user        = $this->makeUser( 'author' );
		$gallery     = $this->makeGallery( $user );
		$trashed     = $this->makeGallery( $user );
		$attachments = $this->seedItems( $gallery, $user, 1 );
		$revision    = $this->revisionOf( $gallery );
		$normal_post = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => $user ) );
		Services::gallery_service()->trash( new Actor( $user ), $trashed, new Revision( $this->revisionOf( $trashed ) ) );
		get_user_by( 'id', $user )->add_cap( 'delete_posts', false );
		wp_set_current_user( $user );

		$this->assertTrue( current_user_can( 'edit_post', $gallery ) );
		$this->assertFalse( current_user_can( 'delete_post', $gallery ) );

		add_filter( 'wp_doing_ajax', '__return_true' );
		$wp_current_filter[] = 'wp_ajax_inline-save';
		try {
			$this->assertFalse( current_user_can( 'edit_post', $gallery ) );
			$this->assertTrue( current_user_can( 'edit_post', $normal_post ) );
			$this->assertTrue( apply_filters( 'wp_insert_post_empty_content', false, array( 'ID' => $gallery ) ) );
			$this->assertTrue( apply_filters( 'wp_insert_post_empty_content', false, array( 'post_type' => PostTypes::GALLERY ) ) );
			$this->assertFalse( apply_filters( 'wp_insert_post_empty_content', false, array( 'ID' => $normal_post ) ) );
			$this->assertFalse( wp_trash_post( $gallery ) );
			$this->assertFalse( wp_delete_post( $gallery, true ) );
			$this->assertFalse( wp_untrash_post( $trashed ) );
		} finally {
			array_pop( $wp_current_filter );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->assertSame( 'draft', get_post_status( $gallery ) );
		$this->assertSame( 'trash', get_post_status( $trashed ) );
		$this->assertSame( $revision, $this->revisionOf( $gallery ) );
		$this->assertSame( 1, Services::items()->count( $gallery ) );
		$this->assertSame( 'attachment', get_post_type( $attachments[0] ) );
	}

	/** @return array<string, array{0: string, 1: string, 2: bool}> */
	public static function native_post_writes(): array {
		return array(
			'individual save' => array( 'post.php', 'POST', false ),
			'bulk edit'       => array( 'edit.php', 'GET', true ),
		);
	}

	/** @dataProvider native_post_writes */
	public function test_native_post_screens_refuse_gallery_saves_without_affecting_other_posts( string $screen, string $request_method, bool $bulk ): void {
		global $pagenow;
		$previous  = $pagenow;
		$method    = $_SERVER['REQUEST_METHOD'] ?? null;
		$bulk_edit = $_GET['bulk_edit'] ?? null;
		$user      = $this->makeUser( 'author' );
		$gallery   = $this->makeGallery( $user );
		wp_set_current_user( $user );
		$pagenow = $screen; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate the native post script.
		set_current_screen( 'edit-post' );
		$_SERVER['REQUEST_METHOD'] = $request_method;
		if ( $bulk ) {
			$_GET['bulk_edit'] = 'Update';
		}
		try {
			$this->assertFalse( current_user_can( 'edit_post', $gallery ) );
			$this->assertTrue( apply_filters( 'wp_insert_post_empty_content', false, array( 'post_type' => PostTypes::GALLERY ) ) );
			$this->assertFalse( apply_filters( 'wp_insert_post_empty_content', false, array( 'post_type' => 'post' ) ) );
		} finally {
			set_current_screen( 'front' );
			if ( null === $method ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $method;
			}
			if ( null === $bulk_edit ) {
				unset( $_GET['bulk_edit'] );
			} else {
				$_GET['bulk_edit'] = $bulk_edit;
			}
			$pagenow = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the native request context.
		}
	}

	public function test_the_gallery_service_can_still_trash_and_restore_with_revision_checks(): void {
		$user      = $this->makeUser( 'author' );
		$gallery   = $this->makeGallery( $user );
		$actor    = new Actor( $user );
		$service  = Services::gallery_service();
		$expected = $this->revisionOf( $gallery );
		$trash    = $service->trash( $actor, $gallery, new Revision( $expected ) );
		$this->assertSame( $expected + 1, $trash->value );
		$this->assertSame( 'trash', get_post_status( $gallery ) );

		$restored = $service->restore( $actor, $gallery, $trash );
		$this->assertSame( 'draft', get_post_status( $gallery ) );
		$this->assertSame( $expected + 2, $restored->revision->value );
	}
}
