<?php
/**
 * Output channel of the WP-CLI commands (replaceable in tests).
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Cli;

interface CliOutputInterface {

	public function line( string $message ): void;

	public function success( string $message ): void;

	public function warning( string $message ): void;

	/** Reports a fatal error; the WP-CLI implementation stops the process. */
	public function error( string $message ): void;
}
