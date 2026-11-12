<?php
/**
 * Media epoch triggers and the public `swg_gallery_changed` action.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Application\Render\EntryPoint;
use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Event\BeforeGalleryDeleting;
use SWGallery\Core\Event\EventInterface;
use SWGallery\Core\Event\GalleryCreated;
use SWGallery\Core\Event\GalleryDeleted;
use SWGallery\Core\Event\GalleryDuplicated;
use SWGallery\Core\Event\GalleryRestored;
use SWGallery\Core\Event\GalleryTrashed;
use SWGallery\Core\Event\GalleryUpdated;
use SWGallery\Core\Event\ItemsAssigned;
use SWGallery\Core\Event\ItemsRemoved;
use SWGallery\Core\Event\ItemsReordered;
use SWGallery\Core\Event\ItemUpdated;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Hooks\CacheInvalidationRegistrar;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Infrastructure\WpLogger;
use SWGallery\WordPress\Rendering\MediaEpoch;

class CacheInvalidationTest extends SwgTestCase {

	use RenderFixtures;

	private int $admin;

	/** @var list<int> */
	private array $changed = array();

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		$this->admin   = $this->makeUser( 'administrator' );
		$this->changed = array();
		delete_option( MediaEpoch::OPTION );
		delete_option( WpLogger::OPTION );
		wp_cache_flush();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		remove_all_actions( 'swg_gallery_changed' );
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	private function epoch(): string {
		return Services::media_epoch()->current();
	}

	/** Records the ids passed to `swg_gallery_changed` and attaches the registrar's event listener to the fresh dispatcher. */
	private function listen(): void {
		Services::reset();
		Services::events()->listen( EventInterface::class, array( new CacheInvalidationRegistrar(), 'on_event' ) );
		add_action(
			'swg_gallery_changed',
			function ( $id ): void {
				$this->changed[] = $id;
			}
		);
	}

	public function test_the_epoch_starts_at_zero_and_changes_on_every_media_trigger(): void {
		$image = $this->makeImage( 'Photo', 1600, 1200, array(), null, 'Alt' );
		delete_option( MediaEpoch::OPTION );
		$this->assertSame( '0', $this->epoch() );

		$steps = array(
			'alt updated'      => static fn() => update_post_meta( $image, '_wp_attachment_image_alt', 'New alt' ),
			'alt added'        => static function () use ( $image ): void {
				delete_post_meta( $image, '_wp_attachment_image_alt' );
				add_post_meta( $image, '_wp_attachment_image_alt', 'Again' );
			},
			'alt deleted'      => static fn() => delete_post_meta( $image, '_wp_attachment_image_alt' ),
			'file replaced'    => static fn() => update_post_meta( $image, '_wp_attached_file', '2026/11/replaced.jpg' ),
			'attachment saved' => static fn() => wp_update_post( array( 'ID' => $image, 'post_title' => 'Renamed' ) ),
			'caption saved'    => static fn() => wp_update_post( array( 'ID' => $image, 'post_excerpt' => 'Caption' ) ),
			'sub-sizes'        => static fn() => wp_update_attachment_metadata( $image, array( 'width' => 1600, 'height' => 1200, 'file' => '2026/11/x.jpg', 'sizes' => array() ) ),
			'trashed'          => static fn() => wp_trash_post( $image ),
			'restored'         => static fn() => wp_untrash_post( $image ),
			'deleted'          => static fn() => wp_delete_attachment( $image, true ),
		);
		foreach ( $steps as $label => $step ) {
			Services::reset();
			$before = $this->epoch();
			$step();
			Services::media_epoch()->flush();
			$this->assertNotSame( $before, $this->epoch(), $label );
		}
	}

	public function test_other_changes_leave_the_epoch_alone(): void {
		$image = $this->makeImage( 'Photo', 1600, 1200 );
		delete_option( MediaEpoch::OPTION );
		Services::reset();

		update_post_meta( $image, 'some_other_key', 'x' );
		wp_update_post( array( 'ID' => self::factory()->post->create(), 'post_title' => 'A page' ) );
		Services::media_epoch()->flush();

		$this->assertSame( '0', $this->epoch() );
	}

	public function test_bursts_write_at_most_twice_per_request(): void {
		$writes = 0;
		add_filter(
			'pre_update_option_' . MediaEpoch::OPTION,
			static function ( $value ) use ( &$writes ) {
				++$writes;
				return $value;
			}
		);
		Services::reset();
		$epoch = Services::media_epoch();

		for ( $i = 0; $i < 50; $i++ ) {
			$epoch->bump();
		}
		$this->assertSame( 1, $writes, 'the first change is written at once' );
		$epoch->flush();
		$this->assertSame( 2, $writes, 'the rest is merged into one write at shutdown' );
		$epoch->flush();
		$this->assertSame( 2, $writes, 'nothing pending, nothing written' );
	}

	public function test_the_epoch_option_is_not_autoloaded(): void {
		Services::reset();
		Services::media_epoch()->bump();

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", MediaEpoch::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_an_edited_alt_text_reaches_visitors_through_the_cache(): void {
		$gallery = $this->makeGalleryPost( $this->admin );
		$image   = $this->makeImage( 'Photo', 1600, 1200, array(), null, 'Old alt' );
		$this->addItems( $gallery, array( $image ) );
		$render = static function () use ( $gallery ): string {
			wp_set_current_user( 0 );
			Services::reset();
			return Services::gallery_renderer()->html( $gallery, EntryPoint::Shortcode );
		};

		$this->assertStringContainsString( 'alt="Old alt"', $render() );
		$this->assertStringContainsString( 'alt="Old alt"', $render(), 'served from the cache' );

		update_post_meta( $image, '_wp_attachment_image_alt', 'New alt' );

		$this->assertStringContainsString( 'alt="New alt"', $render() );
	}

	public function test_a_deleted_attachment_disappears_from_cached_output(): void {
		$gallery = $this->makeGalleryPost( $this->admin );
		$keep    = $this->makeImage( 'Keep', 1600, 1200, array(), null, 'Keep' );
		$drop    = $this->makeImage( 'Drop', 1600, 1200, array(), null, 'Drop' );
		$this->addItems( $gallery, array( $keep, $drop ) );
		$render = static function () use ( $gallery ): string {
			wp_set_current_user( 0 );
			Services::reset();
			return Services::gallery_renderer()->html( $gallery, EntryPoint::Shortcode );
		};
		$this->assertStringContainsString( 'alt="Drop"', $render() );
		$this->assertStringContainsString( 'alt="Drop"', $render() );

		wp_delete_attachment( $drop, true );

		$this->assertStringNotContainsString( 'alt="Drop"', $render() );
		$this->assertStringContainsString( 'alt="Keep"', $render() );
	}

	public function test_gallery_changes_fire_swg_gallery_changed_once_each_with_the_id(): void {
		$this->listen();
		$gallery = $this->makeGalleryPost( $this->admin );
		$a       = $this->makeImage( 'A', 1600, 1200 );
		$b       = $this->makeImage( 'B', 1600, 1200 );
		$actor   = new Actor( $this->admin );
		$items   = Services::item_service();
		wp_set_current_user( $this->admin );

		$revision = Services::revisions()->current( $gallery );
		$result   = $items->assign( $actor, $gallery, array( $a, $b ), $revision );
		$this->assertSame( array( $gallery ), $this->changed, 'assign' );

		$ids      = Services::items()->orderedIds( $gallery );
		$revision = Services::revisions()->current( $gallery );
		$items->reorder( $actor, $gallery, array_reverse( $ids ), $revision );
		$this->assertSame( array( $gallery, $gallery ), $this->changed, 'reorder' );

		$items->remove( $actor, $gallery, $ids[0], Services::revisions()->current( $gallery ) );
		$this->assertSame( array( $gallery, $gallery, $gallery ), $this->changed, 'remove' );

		$this->changed = array();
		$service       = Services::gallery_service();
		$service->trash( $actor, $gallery, Services::revisions()->current( $gallery ) );
		$service->restore( $actor, $gallery, Services::revisions()->current( $gallery ) );
		$service->trash( $actor, $gallery, Services::revisions()->current( $gallery ) );
		$service->delete( $actor, $gallery, Services::revisions()->current( $gallery ) );
		$this->assertSame( array( $gallery, $gallery, $gallery, $gallery ), $this->changed, 'trash, restore, trash, delete' );
		$this->assertContainsOnly( 'int', $this->changed );
		$this->assertSame( array( $a, $b ), $result->added );
	}

	public function test_every_persisted_event_maps_to_the_action_and_vetoable_events_do_not(): void {
		$this->listen();
		$events = array(
			new GalleryCreated( 5, 1, 1 ),
			new GalleryUpdated( 6, 2 ),
			new GalleryTrashed( 7, 3 ),
			new GalleryRestored( 8, 4 ),
			new GalleryDeleted( 9, 5 ),
			new GalleryDuplicated( 10, 5, 1, 1 ),
			new ItemsAssigned( 11, array( 1 ), 2 ),
			new ItemsRemoved( 12, array( 1 ), 2 ),
			new ItemsReordered( 13, 2 ),
			new ItemUpdated( 14, 3, 2 ),
		);
		foreach ( $events as $event ) {
			Services::events()->dispatch( $event );
		}
		Services::events()->dispatchBefore( new BeforeGalleryDeleting( 99, 1 ) );

		$this->assertSame( array( 5, 6, 7, 8, 9, 10, 11, 12, 13, 14 ), $this->changed );
	}

	public function test_a_failing_listener_is_isolated_and_logged(): void {
		$this->listen();
		add_action(
			'swg_gallery_changed',
			static function (): void {
				throw new RuntimeException( 'secret-token-123' );
			}
		);

		Services::events()->dispatch( new GalleryUpdated( 21, 2 ) );
		Services::events()->dispatch( new GalleryUpdated( 22, 3 ) );

		$this->assertSame( array( 21, 22 ), $this->changed, 'later events still reach the action' );
		$codes = array_column( ( new WpLogger() )->recent(), 'code' );
		$this->assertContains( 'swg_listener_failed', $codes );
		$this->assertStringNotContainsString( 'secret-token-123', (string) wp_json_encode( ( new WpLogger() )->recent() ) );
	}

	public function test_the_registrar_attaches_its_hooks(): void {
		$registrar = new CacheInvalidationRegistrar();
		$registrar->register();

		$this->assertNotFalse( has_action( 'edit_attachment', array( $registrar, 'on_attachment' ) ) );
		$this->assertNotFalse( has_filter( 'wp_update_attachment_metadata', array( $registrar, 'on_metadata' ) ) );
		$this->assertNotFalse( has_action( 'updated_post_meta', array( $registrar, 'on_meta' ) ) );
		$this->assertSame( array( 'a' => 1 ), $registrar->on_metadata( array( 'a' => 1 ) ) );
	}
}
