<?php
/**
 * Forwards core events as WordPress hooks `swg_<event_name>` (see docs/hooks.md).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Infrastructure;

use SWGallery\Core\Contract\LoggerInterface;
use SWGallery\Core\Event\EventDispatcherInterface;
use SWGallery\Core\Event\EventInterface;
use SWGallery\Core\Event\VetoableEventInterface;
use SWGallery\Core\Logging\LogLevel;

final class EventBridge {

	public function __construct( private readonly LoggerInterface $logger ) {
	}

	/** Subscribes the bridge to every core event. */
	public function attach( EventDispatcherInterface $dispatcher ): void {
		$dispatcher->listen( EventInterface::class, array( $this, 'forward' ) );
	}

	public function forward( EventInterface $event ): void {
		$hook = 'swg_' . $event->name();
		try {
			if ( $event instanceof VetoableEventInterface ) {
				$result = apply_filters( $hook, true, $event->payload() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $hook is "swg_" plus a core event name.
				$this->apply_veto( $event, $result );
				return;
			}
			do_action( $hook, $event->payload() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $hook is "swg_" plus a core event name.
		} catch ( \Throwable $e ) {
			// Isolated by policy; never log the message, it may contain secrets. A failing veto filter does not veto.
			$this->logger->log(
				LogLevel::Error,
				'swg_listener_failed',
				array(
					'event'     => $event->name(),
					'exception' => $e::class,
				)
			);
		}
	}

	/** Only `false` and WP_Error veto; the reason code is validated by the event itself. */
	private function apply_veto( VetoableEventInterface $event, mixed $result ): void {
		if ( false === $result ) {
			$event->veto( 'swg_vetoed' );
		} elseif ( $result instanceof \WP_Error ) {
			$event->veto( (string) $result->get_error_code() );
		}
	}
}
