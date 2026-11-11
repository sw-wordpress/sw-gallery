<?php
/**
 * `wp swg render`: argument validation and output through the real renderer.
 *
 * @package sw-gallery
 */

use SWGallery\Tests\Support\RenderFixtures;
use SWGallery\WordPress\Cli\CliOutputInterface;
use SWGallery\WordPress\Cli\RenderCommand;
use SWGallery\WordPress\Infrastructure\Services;

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, Squiz.Commenting

/** Records what a command prints. */
class SwgRenderRecordingOutput implements CliOutputInterface {

	/** @var list<array{string, string}> */
	public array $messages = array();

	public function line( string $message ): void {
		$this->messages[] = array( 'line', $message );
	}

	public function success( string $message ): void {
		$this->messages[] = array( 'success', $message );
	}

	public function warning( string $message ): void {
		$this->messages[] = array( 'warning', $message );
	}

	public function error( string $message ): void {
		$this->messages[] = array( 'error', $message );
	}
}

class RenderCommandTest extends SwgTestCase {

	use RenderFixtures;

	private SwgRenderRecordingOutput $out;

	public function set_up(): void {
		parent::set_up();
		$this->purgeGalleries();
		Services::reset();
		$this->out = new SwgRenderRecordingOutput();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->purgeGalleries();
		Services::reset();
		parent::tear_down();
	}

	/**
	 * @param list<string>         $args  Positional arguments.
	 * @param array<string, mixed> $assoc Named arguments.
	 */
	private function run_command( array $args, array $assoc = array() ): void {
		( new RenderCommand( null, $this->out ) )->__invoke( $args, $assoc );
	}

	public function test_it_prints_the_interactive_markup_by_default(): void {
		$gallery = $this->makeGalleryPost( 1 );
		$this->addItems( $gallery, array( $this->makeImage( 'A' ) ) );

		$this->run_command( array( (string) $gallery ) );

		$this->assertSame( 'line', $this->out->messages[0][0] );
		$this->assertStringContainsString( 'data-swg-gallery', $this->out->messages[0][1] );
	}

	public function test_it_prints_the_feed_markup_and_a_page(): void {
		$gallery = $this->makeGalleryPost( 1 );
		$this->addItems( $gallery, array( $this->makeImage( 'A' ), $this->makeImage( 'B' ) ) );

		$this->run_command( array( (string) $gallery ), array( 'entry' => 'feed' ) );
		$this->run_command( array( (string) $gallery ), array( 'page' => '1' ) );

		$this->assertStringStartsWith( '<ul><li>', $this->out->messages[0][1] );
		$this->assertStringContainsString( 'swg-items', $this->out->messages[1][1] );
	}

	public function test_it_warns_when_there_is_nothing_to_print(): void {
		$draft = $this->makeGalleryPost( 1, 'draft' );

		$this->run_command( array( (string) $draft ) );
		$this->run_command( array( '999999' ) );

		$this->assertSame( array( 'warning', 'warning' ), array_column( $this->out->messages, 0 ) );
	}

	public function test_it_rejects_invalid_arguments(): void {
		$this->run_command( array( 'abc' ) );
		$this->run_command( array() );
		$this->run_command( array( '0' ) );
		$this->run_command( array( '5' ), array( 'entry' => 'block' ) );
		$this->run_command( array( '5' ), array( 'page' => '0' ) );
		$this->run_command( array( '5' ), array( 'page' => 'x' ) );

		$this->assertSame( array_fill( 0, 6, 'error' ), array_column( $this->out->messages, 0 ) );
	}
}
