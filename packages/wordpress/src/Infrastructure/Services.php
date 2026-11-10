<?php
/**
 * Holder of the shared infrastructure services (one instance per request). The orchestrator builds the core
 * application services from these.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Infrastructure;

use SWGallery\Core\Event\EventDispatcher;

final class Services {

	private static ?WpLogger $logger                  = null;
	private static ?WpTransactionRunner $transactions = null;
	private static ?EventDispatcher $events           = null;

	public static function logger(): WpLogger {
		return self::$logger ??= new WpLogger();
	}

	public static function transactions(): WpTransactionRunner {
		return self::$transactions ??= new WpTransactionRunner( self::logger() );
	}

	/** The one core event dispatcher; the event bridge listens on it, use cases dispatch through it. */
	public static function events(): EventDispatcher {
		return self::$events ??= new EventDispatcher( self::logger() );
	}

	/** Forgets all instances (tests). */
	public static function reset(): void {
		self::$logger       = null;
		self::$transactions = null;
		self::$events       = null;
	}
}
