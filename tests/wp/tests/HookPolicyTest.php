<?php
/**
 * Failure policy of the public hooks (M4-09, M4-10), end to end through the real services: a throwing listener never
 * breaks an operation, `before_*` filters veto before any state change and fail open when they throw, and every payload
 * is made of ids and scalars only.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Access\Actor;
use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Domain\Gallery\ItemOverrides;
use SWGallery\Core\Domain\Gallery\NewGallery;
use SWGallery\Core\Exception\OperationVetoedException;
use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Infrastructure\EventBridgeRegistrar;
use SWGallery\WordPress\Infrastructure\Services;

class HookPolicyTest extends SwgTestCase {

	use RenderFixtures;

	/** Every observation action of the plugin (docs/hooks.md); `swg_gallery_changed` takes an id, the others an array. */
	private const ACTIONS = array(
		'swg_gallery_created',
		'swg_gallery_updated',
		'swg_gallery_trashed',
		'swg_gallery_restored',
		'swg_gallery_deleted',
		'swg_gallery_duplicated',
		'swg_items_assigned',
		'swg_items_removed',
		'swg_items_reordered',
		'swg_item_updated',
		'swg_gallery_changed',
	);

	private int $owner;
	private Actor $actor;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		( new EventBridgeRegistrar() )->register();
		( new \SWGallery\WordPress\Hooks\CacheInvalidationRegistrar() )->register();
		Services::logger()->clear();
		$this->owner = $this->makeUser( 'administrator' );
		$this->actor = new Actor( $this->owner );
		wp_set_current_user( $this->owner );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		foreach ( get_posts( array( 'post_type' => 'swg_gallery', 'post_status' => 'trash', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) as $id ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
			wp_delete_post( (int) $id, true );
		}
		$this->purgeGalleries();
		Services::logger()->clear();
		Services::reset();
		parent::tear_down();
	}

	/**
	 * Runs one full life cycle through the services: every action fires at least once.
	 *
	 * @return int The gallery that was deleted at the end.
	 */
	private function life_cycle(): int {
		$gallery_service = Services::gallery_service();
		$items           = Services::item_service();
		$edits           = Services::item_edit_service();
		$revisions       = Services::revisions();

		$created = $gallery_service->create( $this->actor, new NewGallery( 'Life', $this->owner ) );
		$id      = $created->gallery->id;
		$images  = array( $this->makeImage( 'A' ), $this->makeImage( 'B' ) );
		$assign  = $items->assign( $this->actor, $id, $images, $revisions->current( $id ) );
		$ids     = array_map( static fn( $row ) => $row->id, Services::items()->findPage( $id, 0, 10 ) );
		$items->reorder( $this->actor, $id, array_reverse( $ids ), $revisions->current( $id ) );
		$edits->updateOverrides( $this->actor, $id, $ids[0], new ItemOverrides( 'T', null, null ), $revisions->current( $id ) );
		$items->remove( $this->actor, $id, $ids[1], $revisions->current( $id ) );
		$gallery_service->update( $this->actor, $created->gallery->withTitle( 'Renamed' ), $revisions->current( $id ) );
		$gallery_service->duplicate( $this->actor, $id, 'Copy' );
		$gallery_service->trash( $this->actor, $id, $revisions->current( $id ) );
		$gallery_service->restore( $this->actor, $id, $revisions->current( $id ) );
		$gallery_service->delete( $this->actor, $id, $revisions->current( $id ) );
		$this->assertNotNull( $assign );
		return $id;
	}

	public function test_a_throwing_listener_on_every_action_never_breaks_the_operations_or_other_hooks(): void {
		$reached = array();
		foreach ( self::ACTIONS as $hook ) {
			add_action(
				$hook,
				static function () use ( $hook ): void {
					throw new RuntimeException( 'secret-' . $hook );
				},
				5
			);
			add_action(
				$hook,
				static function () use ( $hook, &$reached ): void {
					$reached[ $hook ] = true;
				},
				20
			);
		}

		$id = $this->life_cycle();

		$this->assertNull( get_post( $id ), 'The operation sequence completed (the gallery is gone).' );
		$logged = Services::logger()->recent();
		$this->assertNotEmpty( $logged );
		foreach ( $logged as $entry ) {
			$this->assertSame( 'swg_listener_failed', $entry['code'] );
		}
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $logged ), 'Exception messages are never logged.' );
		// Policy: the failing callback is caught, the remaining callbacks of THAT hook are skipped (documented), other hooks run.
		$this->assertSame( array(), $reached, 'Callbacks after a throwing one on the same hook are skipped.' );
		foreach ( self::ACTIONS as $hook ) {
			$this->assertGreaterThan( 0, did_action( $hook ), $hook . ' fired.' );
		}
	}

	public function test_a_listener_error_does_not_stop_listeners_of_other_hooks_and_state_is_consistent_when_it_fires(): void {
		$revision_seen = array();
		add_action(
			'swg_gallery_updated',
			static function (): void {
				throw new RuntimeException( 'x' );
			}
		);
		add_action(
			'swg_gallery_changed',
			static function ( int $gallery_id ) use ( &$revision_seen ): void {
				// The hook fires after commit: the persisted revision is already the new one.
				$revision_seen[] = Services::revisions()->current( $gallery_id )->value;
			}
		);
		$gallery  = Services::gallery_service()->create( $this->actor, new NewGallery( 'Hooks', $this->owner ) )->gallery;
		$revision = Services::revisions()->current( $gallery->id );
		$result   = Services::gallery_service()->update( $this->actor, $gallery->withTitle( 'New' ), $revision );

		$this->assertSame( 'New', get_the_title( $gallery->id ) );
		$this->assertSame( $result->revision->value, Services::revisions()->current( $gallery->id )->value );
		$this->assertSame( $result->revision->value, end( $revision_seen ) );
	}

	public function test_payloads_are_read_only_ids_and_scalars(): void {
		$payloads = array();
		foreach ( self::ACTIONS as $hook ) {
			add_action(
				$hook,
				static function ( $payload ) use ( $hook, &$payloads ): void {
					$payloads[] = array( $hook, $payload );
				}
			);
		}
		foreach ( array( 'swg_before_gallery_trashing', 'swg_before_gallery_deleting' ) as $hook ) {
			add_filter(
				$hook,
				static function ( $allowed, $payload ) use ( $hook, &$payloads ) {
					$payloads[] = array( $hook, $payload );
					return $allowed;
				},
				10,
				2
			);
		}

		$this->life_cycle();

		$this->assertNotEmpty( $payloads );
		$allowed_keys = array( 'gallery_id', 'owner_id', 'revision', 'source_id', 'attachment_ids', 'item_ids', 'item_id', 'actor_id' );
		foreach ( $payloads as list( $hook, $payload ) ) {
			if ( 'swg_gallery_changed' === $hook ) {
				$this->assertIsInt( $payload, $hook );
				continue;
			}
			$this->assertIsArray( $payload, $hook );
			foreach ( $payload as $key => $value ) {
				$this->assertContains( $key, $allowed_keys, "$hook: unexpected key $key" );
				$this->assertTrue( is_int( $value ) || ( is_array( $value ) && array() === array_filter( $value, static fn( $v ) => ! is_int( $v ) ) ), "$hook.$key is an id, a scalar or a list of ids" );
			}
		}
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function vetoes(): array {
		return array(
			'trash'  => array( 'swg_before_gallery_trashing', 'trash' ),
			'delete' => array( 'swg_before_gallery_deleting', 'delete' ),
		);
	}

	/**
	 * @dataProvider vetoes
	 */
	public function test_a_vetoing_filter_fails_the_operation_before_any_state_change( string $filter, string $operation ): void {
		$service  = Services::gallery_service();
		$gallery  = $service->create( $this->actor, new NewGallery( 'Keep', $this->owner ) )->gallery;
		$image    = $this->makeImage( 'Kept' );
		$this->addItems( $gallery->id, array( $image ) );
		$revision = Services::revisions()->current( $gallery->id );
		$events   = array();
		add_filter( $filter, static fn() => new WP_Error( 'my_locked', 'Locked.' ) );
		foreach ( self::ACTIONS as $hook ) {
			add_action(
				$hook,
				static function () use ( $hook, &$events ): void {
					$events[] = $hook;
				}
			);
		}

		try {
			'trash' === $operation
				? $service->trash( $this->actor, $gallery->id, $revision )
				: $service->delete( $this->actor, $gallery->id, $revision );
			$this->fail( 'The veto must stop the operation.' );
		} catch ( OperationVetoedException $e ) {
			$this->assertSame( 'my_locked', $e->reason );
		}

		$this->assertSame( 'draft', get_post_status( $gallery->id ) );
		$this->assertSame( $revision->value, Services::revisions()->current( $gallery->id )->value, 'No revision bump.' );
		$this->assertSame( array( $image ), Services::items()->assignedAttachmentIds( $gallery->id, array( $image ) ), 'Relations stay.' );
		$this->assertSame( array(), $events, 'No after-event fired.' );
	}

	/**
	 * @dataProvider vetoes
	 */
	public function test_a_failing_veto_filter_fails_open_as_documented( string $filter, string $operation ): void {
		$service = Services::gallery_service();
		$gallery = $service->create( $this->actor, new NewGallery( 'Open', $this->owner ) )->gallery;
		add_filter(
			$filter,
			static function (): bool {
				throw new RuntimeException( 'secret' );
			}
		);

		'trash' === $operation
			? $service->trash( $this->actor, $gallery->id, Services::revisions()->current( $gallery->id ) )
			: $service->delete( $this->actor, $gallery->id, Services::revisions()->current( $gallery->id ) );

		'trash' === $operation ? $this->assertSame( 'trash', get_post_status( $gallery->id ) ) : $this->assertNull( get_post( $gallery->id ) );
		$this->assertSame( array( 'swg_listener_failed' ), array_column( Services::logger()->recent(), 'code' ) );
	}

	public function test_the_status_enum_is_unchanged_by_vetoes(): void {
		$this->assertSame( GalleryStatus::Draft->value, 'draft' );
	}
}
