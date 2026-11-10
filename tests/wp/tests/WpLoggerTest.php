<?php
/**
 * Logger sink: error_log switch, bounded non-autoloaded ring buffer and safe context.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Logging\LogLevel;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Infrastructure\WpLogger;

class WpLoggerTest extends SwgTestCase {

	/** @var list<string> */
	private array $lines = array();

	private function logger( ?bool $debug_log ): WpLogger {
		$this->lines = array();
		return new WpLogger(
			$debug_log,
			function ( string $line ): void {
				$this->lines[] = $line;
			}
		);
	}

	public function test_error_log_is_written_only_when_debug_logging_is_on(): void {
		$this->logger( false )->log( LogLevel::Warning, 'swg_x', array( 'id' => 5 ) );
		$this->assertSame( array(), $this->lines );

		$this->logger( true )->log( LogLevel::Warning, 'swg_x', array( 'id' => 5 ) );
		$this->assertSame( array( 'sw-gallery warning swg_x {"id":5}' ), $this->lines );
	}

	public function test_the_default_follows_wp_debug_log(): void {
		$expected    = defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
		$logger      = new WpLogger(
			null,
			function ( string $line ): void {
				$this->lines[] = $line;
			}
		);
		$this->lines = array();
		$logger->log( LogLevel::Info, 'swg_x' );
		$this->assertSame( $expected ? 1 : 0, count( $this->lines ) );
	}

	public function test_only_errors_enter_the_ring_buffer_which_is_not_autoloaded(): void {
		global $wpdb;
		$logger = $this->logger( false );
		$logger->log( LogLevel::Debug, 'swg_a' );
		$logger->log( LogLevel::Info, 'swg_b' );
		$logger->log( LogLevel::Warning, 'swg_c' );
		$this->assertSame( array(), $logger->recent() );

		$logger->log( LogLevel::Error, 'swg_first', array( 'gallery_id' => 3 ) );
		$logger->log( LogLevel::Error, 'swg_second' );

		$recent = $logger->recent();
		$this->assertSame( array( 'swg_second', 'swg_first' ), array_column( $recent, 'code' ), 'Newest first.' );
		$this->assertSame( array( 'gallery_id' => 3 ), $recent[1]['context'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $recent[0]['time'] );
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", WpLogger::OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ), 'The ring buffer must not be autoloaded.' );
	}

	public function test_the_ring_buffer_keeps_only_the_last_fifty_errors(): void {
		$logger = $this->logger( false );
		for ( $i = 1; $i <= 55; $i++ ) {
			$logger->log( LogLevel::Error, 'swg_e', array( 'n' => $i ) );
		}

		$recent = $logger->recent();
		$this->assertCount( WpLogger::MAX_ENTRIES, $recent );
		$this->assertSame( 55, $recent[0]['context']['n'] );
		$this->assertSame( 6, $recent[49]['context']['n'] );

		$logger->clear();
		$this->assertSame( array(), $logger->recent() );
	}

	public function test_the_context_is_reduced_to_safe_scalars(): void {
		$logger = $this->logger( true );

		$logger->log(
			LogLevel::Error,
			'swg_ctx',
			array(
				'gallery_id' => 7,
				'ratio'      => 1.5,
				'flag'       => true,
				'nothing'    => null,
				'event'      => 'items_assigned',
				'api_token'  => 'abc',
				'password'   => 'pw',
				'remote_url' => 'https://example.com/?token=1',
				'location'   => 'https://example.com/?token=1',
				'where'      => '/var/www/secret.php',
				'object'     => new \stdClass(),
				'list'       => array( 1, 2 ),
				'Bad Key'    => 'x',
				'long'       => str_repeat( 'a', 500 ),
				'control'    => "a\x00b\nc",
				'author_id'  => 9,
			)
		);

		$context = $logger->recent()[0]['context'];
		$this->assertSame( array( 'gallery_id', 'ratio', 'flag', 'nothing', 'event', 'location', 'where', 'long', 'control', 'author_id' ), array_keys( $context ) );
		$this->assertSame( '[redacted]', $context['location'] );
		$this->assertSame( '[redacted]', $context['where'] );
		$this->assertSame( 120, strlen( $context['long'] ) );
		$this->assertSame( 'a b c', $context['control'] );
		$this->assertStringNotContainsString( 'token', $this->lines[0] );
		$this->assertStringNotContainsString( 'secret', $this->lines[0] );
		$this->assertStringNotContainsString( 'pw', $this->lines[0] );
	}

	public function test_an_invalid_code_is_replaced(): void {
		$logger = $this->logger( false );
		$logger->log( LogLevel::Error, 'Not A Code with token=abc' );
		$this->assertSame( 'swg_invalid_log_code', $logger->recent()[0]['code'] );
	}

	public function test_a_corrupted_option_is_tolerated(): void {
		$logger = $this->logger( false );
		update_option( WpLogger::OPTION, 'garbage', false );
		$this->assertSame( array(), $logger->recent() );

		update_option(
			WpLogger::OPTION,
			array(
				'junk',
				array( 'time' => 1 ),
				array(
					'time'    => 't',
					'code'    => 'swg_ok',
					'context' => 'nope',
				),
			),
			false
		);
		$this->assertSame(
			array(
				array(
					'time'    => 't',
					'code'    => 'swg_ok',
					'context' => array(),
				),
			),
			$logger->recent()
		);

		update_option( WpLogger::OPTION, 'garbage', false );
		$logger->log( LogLevel::Error, 'swg_fresh' );
		$this->assertSame( array( 'swg_fresh' ), array_column( $logger->recent(), 'code' ) );
	}

	public function test_the_service_holder_shares_instances_until_reset(): void {
		Services::reset();
		$logger = Services::logger();

		$this->assertSame( $logger, Services::logger() );
		$this->assertSame( Services::transactions(), Services::transactions() );
		$this->assertSame( Services::events(), Services::events() );

		Services::reset();
		$this->assertNotSame( $logger, Services::logger() );
	}
}
