<?php
/**
 * Activation, deactivation and uninstall against a real database. Attachments are never touched.
 *
 * @package sw-gallery
 */

use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Lifecycle;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;

class LifecycleTest extends SwgTestCase {

	/** @var list<int> */
	private array $attachments = array();
	private int $gallery_id    = 0;

	public function set_up(): void {
		parent::set_up();
		$this->gallery_id = self::factory()->post->create(
			array(
				'post_type'  => PostTypes::GALLERY,
				'post_title' => 'Holiday',
			)
		);
		foreach ( array( 'one.jpg', 'two.jpg' ) as $file ) {
			$this->attachments[] = self::factory()->attachment->create_object(
				$file,
				0,
				array(
					'post_mime_type' => 'image/jpeg',
					'post_type'      => 'attachment',
				)
			);
		}
		$repository = new WpdbGalleryItemRepository();
		foreach ( $this->attachments as $attachment ) {
			$repository->append( $this->gallery_id, $attachment );
		}
	}

	public function tear_down(): void {
		// Uninstall tests commit implicitly (DDL): remove what they created.
		foreach ( $this->attachments as $attachment ) {
			wp_delete_attachment( $attachment, true );
		}
		wp_delete_post( $this->gallery_id, true );
		$this->attachments = array();
		delete_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL );
		parent::tear_down();
	}

	public function test_activate_creates_the_table_and_the_version_option(): void {
		$this->drop_items_table();
		delete_option( Schema::OPTION );

		Lifecycle::activate();

		$this->assertTrue( $this->items_table_exists() );
		$this->assertSame( Schema::CURRENT, (int) get_option( Schema::OPTION ) );
	}

	public function test_deactivate_keeps_all_data(): void {
		Lifecycle::deactivate();

		$this->assertTrue( $this->items_table_exists() );
		$this->assertSame( 2, ( new WpdbGalleryItemRepository() )->count( $this->gallery_id ) );
		$this->assertSame( PostTypes::GALLERY, get_post_type( $this->gallery_id ) );
		$this->assertNotFalse( get_option( Schema::OPTION ) );
	}

	public function test_uninstall_preserves_everything_without_opt_in(): void {
		Lifecycle::uninstall();

		$this->assertTrue( $this->items_table_exists() );
		$this->assertSame( 2, ( new WpdbGalleryItemRepository() )->count( $this->gallery_id ) );
		$this->assertSame( PostTypes::GALLERY, get_post_type( $this->gallery_id ) );
		$this->assertNotFalse( get_option( Schema::OPTION ) );
	}

	public function test_uninstall_preserves_everything_when_the_option_is_not_exactly_one(): void {
		update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '0' );
		Lifecycle::uninstall();
		update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, 'yes' );
		Lifecycle::uninstall();

		$this->assertTrue( $this->items_table_exists() );
		$this->assertSame( PostTypes::GALLERY, get_post_type( $this->gallery_id ) );
	}

	public function test_opt_in_uninstall_removes_plugin_data_but_never_attachments_or_other_content(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '1' );
		update_option( 'unrelated_option', 'kept' );
		$this->use_real_ddl();

		Lifecycle::uninstall();

		$this->assertFalse( $this->items_table_exists() );
		$this->assertNull( get_post( $this->gallery_id ) );
		$this->assertFalse( get_option( Schema::OPTION ) );
		$this->assertFalse( get_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL ) );
		foreach ( $this->attachments as $attachment ) {
			$this->assertInstanceOf( WP_Post::class, get_post( $attachment ), 'Attachments are never deleted.' );
		}
		$this->assertInstanceOf( WP_Post::class, get_post( $page ) );
		$this->assertSame( 'kept', get_option( 'unrelated_option' ) );
		wp_delete_post( $page, true );
		delete_option( 'unrelated_option' );
	}

	public function test_uninstall_php_delegates_to_the_lifecycle(): void {
		update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '1' );
		$this->use_real_ddl();
		define( 'WP_UNINSTALL_PLUGIN', 'sw-gallery/sw-gallery.php' );

		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertFalse( $this->items_table_exists() );
		$this->assertNull( get_post( $this->gallery_id ) );
	}
}
