<?php
/**
 * WP-CLI output. WP_CLI is only available inside a CLI process, so it is called dynamically.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Cli;

final class WpCliOutput implements CliOutputInterface {

	public function line( string $message ): void {
		$this->call( 'line', $message );
	}

	public function success( string $message ): void {
		$this->call( 'success', $message );
	}

	public function warning( string $message ): void {
		$this->call( 'warning', $message );
	}

	public function error( string $message ): void {
		$this->call( 'error', $message );
	}

	private function call( string $method, string $message ): void {
		$callback = array( 'WP_CLI', $method );
		if ( is_callable( $callback ) ) {
			call_user_func( $callback, $message );
		}
	}
}
