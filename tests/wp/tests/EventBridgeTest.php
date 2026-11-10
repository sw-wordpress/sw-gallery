<?php
/**
 * Core events as `swg_*` hooks, including veto filters and listener isolation.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Event\BeforeGalleryDeleting;
use SWGallery\Core\Event\BeforeGalleryTrashing;
use SWGallery\Core\Event\EventDispatcher;
use SWGallery\Core\Event\GalleryCreated;
use SWGallery\Core\Event\ItemsAssigned;
use SWGallery\Tests\Support\RecordingLogger;
use SWGallery\WordPress\Infrastructure\EventBridge;
use SWGallery\WordPress\Infrastructure\EventBridgeRegistrar;
use SWGallery\WordPress\Infrastructure\Services;

class EventBridgeTest extends SwgTestCase {

	private RecordingLogger $logger;
	private EventDispatcher $dispatcher;

	public function set_up(): void {
		parent::set_up();
		$this->logger     = new RecordingLogger();
		$this->dispatcher = new EventDispatcher( $this->logger );
		( new EventBridge( $this->logger ) )->attach( $this->dispatcher );
	}

	public function test_events_become_actions_with_the_documented_payload(): void {
		$received = array();
		add_action(
			'swg_items_assigned',
			static function ( array $payload ) use ( &$received ): void {
				$received[] = $payload;
			}
		);

		$this->dispatcher->dispatch( new ItemsAssigned( 4, array( 11, 12 ), 3 ) );

		$this->assertSame(
			array(
				array(
					'gallery_id'     => 4,
					'attachment_ids' => array( 11, 12 ),
					'revision'       => 3,
				),
			),
			$received
		);
		$this->assertSame( 1, did_action( 'swg_items_assigned' ) );
	}

	public function test_a_throwing_action_is_isolated_and_logged_without_its_message(): void {
		add_action(
			'swg_gallery_created',
			static function (): void {
				throw new \RuntimeException( 'secret-token-123' );
			}
		);

		$this->dispatcher->dispatch( new GalleryCreated( 1, 2, 1 ) );

		$this->assertSame( array( 'swg_listener_failed' ), $this->logger->codes() );
		$this->assertSame(
			array(
				'event'     => 'gallery_created',
				'exception' => \RuntimeException::class,
			),
			$this->logger->entries[0]['context']
		);
	}

	public function test_vetoable_events_use_filters_and_are_allowed_by_default(): void {
		$this->assertTrue( $this->dispatcher->dispatchBefore( new BeforeGalleryTrashing( 5, 1 ) ) );
		$this->assertTrue( $this->dispatcher->dispatchBefore( new BeforeGalleryDeleting( 5, 1 ) ) );
		$this->assertSame( 0, did_action( 'swg_before_gallery_trashing' ), 'It is a filter, not an action.' );
	}

	public function test_a_filter_returning_false_vetoes_with_the_default_code(): void {
		$seen = array();
		add_filter(
			'swg_before_gallery_trashing',
			static function ( $allowed, array $payload ) use ( &$seen ) {
				$seen = $payload;
				return false;
			},
			10,
			2
		);
		$event = new BeforeGalleryTrashing( 5, 9 );

		$this->assertFalse( $this->dispatcher->dispatchBefore( $event ) );
		$this->assertSame( 'swg_vetoed', $event->vetoCode() );
		$this->assertSame(
			array(
				'gallery_id' => 5,
				'actor_id'   => 9,
			),
			$seen
		);
	}

	public function test_a_wp_error_vetoes_with_its_safe_code_and_unsafe_codes_are_replaced(): void {
		add_filter( 'swg_before_gallery_deleting', static fn() => new WP_Error( 'swg_gallery_locked', 'secret message' ) );
		$event = new BeforeGalleryDeleting( 5, 9 );
		$this->assertFalse( $this->dispatcher->dispatchBefore( $event ) );
		$this->assertSame( 'swg_gallery_locked', $event->vetoCode() );

		remove_all_filters( 'swg_before_gallery_deleting' );
		add_filter( 'swg_before_gallery_deleting', static fn() => new WP_Error( 'Locked: https://x/?token=1', 'm' ) );
		$event = new BeforeGalleryDeleting( 5, 9 );
		$this->assertFalse( $this->dispatcher->dispatchBefore( $event ) );
		$this->assertSame( 'swg_vetoed', $event->vetoCode() );
	}

	public function test_other_filter_results_do_not_veto(): void {
		add_filter( 'swg_before_gallery_trashing', static fn() => null );
		$this->assertTrue( $this->dispatcher->dispatchBefore( new BeforeGalleryTrashing( 5, 1 ) ) );
	}

	public function test_a_throwing_filter_is_logged_and_does_not_veto(): void {
		add_filter(
			'swg_before_gallery_trashing',
			static function (): bool {
				throw new \LogicException( 'private' );
			}
		);

		$this->assertTrue( $this->dispatcher->dispatchBefore( new BeforeGalleryTrashing( 5, 1 ) ) );
		$this->assertSame( array( 'swg_listener_failed' ), $this->logger->codes() );
		$this->assertSame( 'before_gallery_trashing', $this->logger->entries[0]['context']['event'] );
	}

	public function test_the_registrar_connects_the_shared_dispatcher(): void {
		Services::reset();
		( new EventBridgeRegistrar() )->register();
		$fired = 0;
		add_action(
			'swg_gallery_created',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		Services::events()->dispatch( new GalleryCreated( 1, 2, 1 ) );

		$this->assertSame( 1, $fired );
		Services::reset();
	}
}
