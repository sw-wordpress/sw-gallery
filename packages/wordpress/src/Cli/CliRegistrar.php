<?php
/**
 * Registers the WP-CLI commands, only inside a WP-CLI process.
 *
 * @package sw-gallery
 */

declare(strict_types=1);

namespace SWGallery\WordPress\Cli;

use SWGallery\WordPress\Bootstrap\RegistrarInterface;

final class CliRegistrar implements RegistrarInterface {

	private bool $cli;

	/** @var callable(string, object): mixed */
	private $add_command;

	/**
	 * @param bool|null                              $cli         Whether running inside WP-CLI; null reads the WP_CLI constant.
	 * @param (callable(string, object): mixed)|null $add_command Registers a command (WP_CLI::add_command by default).
	 */
	public function __construct( ?bool $cli = null, ?callable $add_command = null ) {
		$this->cli         = $cli ?? ( defined( 'WP_CLI' ) && WP_CLI );
		$this->add_command = $add_command ?? static function ( string $name, object $command ) {
			$callback = array( 'WP_CLI', 'add_command' );
			return is_callable( $callback ) ? call_user_func( $callback, $name, $command ) : null;
		};
	}

	public function register(): void {
		if ( $this->cli ) {
			add_action( 'cli_init', array( $this, 'register_commands' ) );
		}
	}

	public function register_commands(): void {
		( $this->add_command )( 'swg repair', new RepairCommand() );
	}
}
