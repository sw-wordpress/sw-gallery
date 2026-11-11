<?php
/**
 * The wired application services against real WordPress and the real database: the whole stack from the core use
 * cases through the adapters, including permissions, revisions, events and the layout registry.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\GallerySettings;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Core\Domain\Gallery\Revision;
use SWGallery\Core\Exception\AccessDeniedException;
use SWGallery\Core\Exception\RevisionConflictException;
use SWGallery\Core\Exception\ValidationFailedException;
use SWGallery\Tests\Support\WpFixtures;
use SWGallery\WordPress\Infrastructure\EventBridgeRegistrar;
use SWGallery\WordPress\Infrastructure\Services;

class ServicesIntegrationTest extends SwgTestCase {

	use WpFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		// reset() creates a new dispatcher; attach the swg_* hook bridge to it again, as booting the plugin does.
		( new EventBridgeRegistrar() )->register();
		$this->ensureGalleryTypeRegistered();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	public function test_the_layout_registry_ships_the_grid_layout(): void {
		$this->assertTrue( Services::layouts()->has( 'grid' ) );
		$this->assertSame( 'grid', Services::layouts()->resolve( 'unknown-layout' )->layout->getId() );
		$this->assertTrue( Services::layouts()->resolve( 'unknown-layout' )->fellBack );
	}

	public function test_a_full_gallery_life_cycle_through_the_wired_services(): void {
		$user  = $this->makeUser( 'editor' );
		$actor = new Actor( $user );
		$fired = array();
		foreach ( array( 'gallery_created', 'items_assigned', 'items_reordered', 'item_updated', 'gallery_trashed', 'gallery_restored', 'gallery_deleted' ) as $event ) {
			add_action(
				'swg_' . $event,
				static function () use ( &$fired, $event ): void {
					$fired[] = $event;
				}
			);
		}

		// Create: revision 1, owner is the actor, status draft.
		$created = Services::gallery_service()->create( $actor, new NewGallery( 'Trip', 999, 'Holiday' ) );
		$gid     = $created->gallery->id;
		$this->assertSame( 1, $created->revision->value );
		$this->assertSame( $user, $created->gallery->ownerId );
		$this->assertSame( GalleryStatus::Draft, $created->gallery->status );

		// Assign three images, one is reported missing honestly.
		$images   = array( $this->makeAttachment( $user ), $this->makeAttachment( $user ), $this->makeAttachment( $user ) );
		$assigned = Services::item_service()->assign( $actor, $gid, array_merge( $images, array( 999999 ) ), $created->revision );
		$this->assertSame( $images, $assigned->added );
		$this->assertSame( array( 999999 ), $assigned->missing );
		$this->assertSame( 2, $assigned->revision->value );

		// Reorder, then override a title (null = inherit, '' = blank).
		$ids       = Services::items()->orderedIds( $gid );
		$reordered = Services::item_service()->reorder( $actor, $gid, array_reverse( $ids ), $assigned->revision );
		$this->assertSame( 3, $reordered->value );
		$this->assertSame( array_reverse( $ids ), Services::items()->orderedIds( $gid ) );
		$edited = Services::item_edit_service()->updateOverrides( $actor, $gid, $ids[0], new ItemOverrides( 'Local title', null, '' ), $reordered );
		$this->assertSame( 4, $edited->value );
		$item = Services::items()->findPage( $gid, 0, 10 )[2];
		$this->assertSame( $ids[0], $item->id );
		$this->assertSame( 'Local title', $item->overrides->title );
		$this->assertNull( $item->overrides->caption );
		$this->assertSame( '', $item->overrides->alt );

		// A stale revision is a conflict and changes nothing.
		try {
			Services::item_service()->remove( $actor, $gid, $ids[1], new Revision( 1 ) );
			$this->fail( 'A stale revision must raise a conflict.' );
		} catch ( RevisionConflictException $e ) {
			$this->assertCount( 3, Services::items()->orderedIds( $gid ) );
		}

		// Trash keeps the relations, restore returns a draft, delete removes gallery and relations but no attachment.
		$trashed = Services::gallery_service()->trash( $actor, $gid, $edited );
		$this->assertSame( GalleryStatus::Trash, Services::galleries()->find( $gid )?->status );
		$this->assertCount( 3, Services::items()->orderedIds( $gid ) );
		$restored = Services::gallery_service()->restore( $actor, $gid, $trashed );
		$this->assertSame( GalleryStatus::Draft, $restored->gallery->status );
		Services::gallery_service()->delete( $actor, $gid, $restored->revision );
		$this->assertNull( Services::galleries()->find( $gid ) );
		$this->assertSame( array(), Services::items()->orderedIds( $gid ) );
		foreach ( $images as $image ) {
			$this->assertSame( 'attachment', get_post_type( $image ) );
		}

		$this->assertSame(
			array( 'gallery_created', 'items_assigned', 'items_reordered', 'item_updated', 'gallery_trashed', 'gallery_restored', 'gallery_deleted' ),
			$fired
		);
	}

	public function test_a_foreign_gallery_cannot_be_changed_without_the_capability(): void {
		$owner    = new Actor( $this->makeUser( 'author' ) );
		$intruder = new Actor( $this->makeUser( 'author' ) );
		$created  = Services::gallery_service()->create( $owner, new NewGallery( 'Mine', $owner->userId ) );
		$image    = $this->makeAttachment( $owner->userId );

		try {
			Services::item_service()->assign( $intruder, $created->gallery->id, array( $image ), $created->revision );
			$this->fail( 'A foreign author must not edit the gallery.' );
		} catch ( AccessDeniedException $e ) {
			$this->assertSame( 'swg_forbidden', $e->errorCode() );
			$this->assertSame( 1, Services::revisions()->current( $created->gallery->id )->value );
		}
	}

	public function test_unknown_layouts_and_invalid_settings_are_rejected_before_anything_is_written(): void {
		$actor = new Actor( $this->makeUser( 'editor' ) );

		foreach ( array( new GallerySettings( 'does-not-exist' ), new GallerySettings( 'grid', \SWGallery\Core\Domain\Gallery\SortMode::Manual, array( 'columns' => 0 ) ) ) as $settings ) {
			try {
				Services::gallery_service()->create( $actor, new NewGallery( 'Bad', $actor->userId, '', GalleryStatus::Draft, $settings ) );
				$this->fail( 'Invalid layout settings must be rejected.' );
			} catch ( \SWGallery\Core\Exception\GalleryException $e ) {
				$this->assertContains( $e::class, array( ValidationFailedException::class, \SWGallery\Core\Exception\UnknownLayoutException::class ) );
			}
		}
		$this->assertSame( 0, Services::galleries()->search( new \SWGallery\Core\Domain\Gallery\GalleryQuery() )->total );
	}

	public function test_valid_responsive_settings_are_stored_and_round_trip(): void {
		$actor    = new Actor( $this->makeUser( 'editor' ) );
		$settings = new GallerySettings( 'grid', \SWGallery\Core\Domain\Gallery\SortMode::TitleAsc, array( 'columns' => array( 'base' => 2, 'lg' => 5 ), 'gap' => 12, 'future_key' => 'kept' ) );

		$created = Services::gallery_service()->create( $actor, new NewGallery( 'Responsive', $actor->userId, '', GalleryStatus::Draft, $settings ) );
		$found   = Services::galleries()->find( $created->gallery->id );

		$this->assertNotNull( $found );
		$this->assertSame( array( 'columns' => array( 'base' => 2, 'lg' => 5 ), 'gap' => 12, 'future_key' => 'kept' ), $found->settings->values );
	}
}
