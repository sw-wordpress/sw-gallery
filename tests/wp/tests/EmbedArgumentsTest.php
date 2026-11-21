<?php
/**
 * The allowlist of per-embed overrides shared by the shortcode and the PHP API.
 *
 * @package sw-gallery
 */

use SWGallery\WordPress\Frontend\EmbedArguments;
use SWGallery\WordPress\Infrastructure\Services;

class EmbedArgumentsTest extends SwgTestCase {

	private function arguments(): EmbedArguments {
		return new EmbedArguments( Services::layouts() );
	}

	public function test_valid_values_are_accepted_in_native_and_string_form(): void {
		$this->assertSame(
			array(
				'layout'   => 'grid',
				'settings' => array(
					'columns' => 12,
					'gap'     => 0,
				),
				'page'     => 3,
			),
			$this->arguments()->options( 7, array( 'layout' => ' grid ', 'columns' => '12', 'gap' => 0, 'page' => '3' ), false, array() )
		);
	}

	public function test_per_page_is_limited_to_1_to_200(): void {
		$this->assertSame( array( 'per_page' => 12 ), $this->arguments()->options( 7, array( 'per_page' => '12' ), false, array() ) );
		$this->assertSame( array( 'per_page' => 200 ), $this->arguments()->options( 7, array( 'per_page' => 200 ), false, array() ) );
		foreach ( array( 0, 201, '-1', 'abc', '1.5', array( 5 ) ) as $invalid ) {
			$this->assertSame( array(), $this->arguments()->options( 7, array( 'per_page' => $invalid ), false, array() ) );
		}
	}

	public function test_the_request_page_wins_over_the_attribute(): void {
		$this->assertSame( array( 'page' => 4 ), $this->arguments()->options( 7, array( 'page' => 2 ), false, array( 'swg_page_7' => '4' ) ) );
		$this->assertSame( array( 'page' => 2 ), $this->arguments()->options( 7, array( 'page' => 2 ), false, array( 'swg_page_8' => '4' ) ) );
		$this->assertSame( array( 'page' => 2 ), $this->arguments()->options( 7, array( 'page' => 2 ), false, array( 'swg_page_7' => array( '9' ) ) ) );
	}

	public function test_the_query_defaults_to_the_request(): void {
		$_GET['swg_page_7'] = '5';
		$this->assertSame( 5, $this->arguments()->requested_page( 7 ) );
		unset( $_GET['swg_page_7'] );
		$this->assertNull( $this->arguments()->requested_page( 7 ) );
	}

	/**
	 * @return iterable<string, array{mixed, mixed}>
	 */
	public static function eager_values(): iterable {
		yield 'true' => array( true, true );
		yield 'false' => array( false, false );
		yield 'one' => array( 1, true );
		yield 'zero' => array( 0, false );
		yield 'string true' => array( 'true', true );
		yield 'string false' => array( 'false', false );
		yield 'string 0' => array( '0', false );
		yield 'string 1' => array( '1', true );
		yield 'junk' => array( 'yes please', null );
		yield 'array' => array( array( true ), null );
		yield 'two' => array( 2, null );
	}

	/**
	 * @dataProvider eager_values
	 * @param mixed $input    Raw value.
	 * @param mixed $expected Accepted boolean or null when dropped.
	 */
	public function test_eager_is_only_accepted_for_the_api( mixed $input, mixed $expected ): void {
		$with = $this->arguments()->options( 7, array( 'eager' => $input ), true, array() );
		if ( null === $expected ) {
			$this->assertSame( array(), $with );
		} else {
			$this->assertSame( array( 'eager' => $expected ), $with );
		}
		$this->assertSame( array(), $this->arguments()->options( 7, array( 'eager' => $input ), false, array() ) );
	}
}
