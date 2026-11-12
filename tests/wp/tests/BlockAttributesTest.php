<?php
/**
 * Server-side validation of the block attributes: valid values pass, everything else is dropped, never echoed.
 *
 * @package sw-gallery
 */

use SWGallery\WordPress\Block\BlockAttributes;

class BlockAttributesTest extends SwgTestCase {

	/**
	 * @return iterable<string, array{mixed, int}>
	 */
	public static function gallery_ids(): iterable {
		yield 'int' => array( 12, 12 );
		yield 'digit string' => array( '12', 12 );
		yield 'max int32' => array( 2147483647, 2147483647 );
		yield 'zero' => array( 0, 0 );
		yield 'negative' => array( -4, 0 );
		yield 'too large' => array( 2147483648, 0 );
		yield 'huge string' => array( '99999999999999999999', 0 );
		yield 'float' => array( 3.0, 0 );
		yield 'float string' => array( '3.5', 0 );
		yield 'signed string' => array( '+3', 0 );
		yield 'leading zero' => array( '03', 0 );
		yield 'exponent' => array( '1e3', 0 );
		yield 'whitespace' => array( ' 3', 0 );
		yield 'sql' => array( '1 OR 1=1', 0 );
		yield 'script' => array( '<script>alert(1)</script>', 0 );
		yield 'null' => array( null, 0 );
		yield 'true' => array( true, 0 );
		yield 'array' => array( array( 5 ), 0 );
	}

	/**
	 * @dataProvider gallery_ids
	 * @param mixed $value Raw attribute.
	 */
	public function test_the_gallery_id_is_a_strict_positive_integer( $value, int $expected ): void {
		$this->assertSame( $expected, BlockAttributes::gallery_id( array( 'galleryId' => $value ) ) );
	}

	public function test_a_missing_gallery_id_is_zero(): void {
		$this->assertSame( 0, BlockAttributes::gallery_id( array() ) );
	}

	public function test_valid_overrides_map_to_render_options(): void {
		$options = BlockAttributes::options(
			array(
				'galleryId' => 3,
				'layout'    => 'grid',
				'columns'   => 4,
				'gap'       => 0,
				'eager'     => false,
			)
		);
		$this->assertSame(
			array(
				'layout'   => 'grid',
				'settings' => array(
					'columns' => 4,
					'gap'     => 0,
				),
				'eager'    => false,
			),
			$options
		);
	}

	public function test_the_bounds_are_inclusive(): void {
		$this->assertSame(
			array(
				'settings' => array(
					'columns' => 1,
					'gap'     => 0,
				),
			),
			BlockAttributes::options(
				array(
					'columns' => 1,
					'gap'     => 0,
				)
			)
		);
		$this->assertSame(
			array(
				'settings' => array(
					'columns' => 12,
					'gap'     => 64,
				),
			),
			BlockAttributes::options(
				array(
					'columns' => 12,
					'gap'     => 64,
				)
			)
		);
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function hostile_attributes(): iterable {
		yield 'layout with markup' => array( array( 'layout' => '"><script>alert(1)</script>' ) );
		yield 'layout uppercase' => array( array( 'layout' => 'Grid' ) );
		yield 'layout traversal' => array( array( 'layout' => '../../etc/passwd' ) );
		yield 'layout too long' => array( array( 'layout' => 'a' . str_repeat( 'b', 64 ) ) );
		yield 'layout array' => array( array( 'layout' => array( 'grid' ) ) );
		yield 'layout int' => array( array( 'layout' => 5 ) );
		yield 'columns zero' => array( array( 'columns' => 0 ) );
		yield 'columns 13' => array( array( 'columns' => 13 ) );
		yield 'columns negative' => array( array( 'columns' => -1 ) );
		yield 'columns float' => array( array( 'columns' => 2.5 ) );
		yield 'columns script' => array( array( 'columns' => '<b>3</b>' ) );
		yield 'columns array' => array( array( 'columns' => array( 3 ) ) );
		yield 'gap 65' => array( array( 'gap' => 65 ) );
		yield 'gap negative' => array( array( 'gap' => -1 ) );
		yield 'gap css' => array( array( 'gap' => '8px; background:url(x)' ) );
		yield 'eager string' => array( array( 'eager' => 'false' ) );
		yield 'eager int' => array( array( 'eager' => 0 ) );
		yield 'unknown media list' => array( array( 'ids' => array( 1, 2, 3 ) ) );
		yield 'unknown settings' => array( array( 'settings' => array( 'columns' => 3 ) ) );
		yield 'unknown page' => array( array( 'page' => 2 ) );
	}

	/**
	 * @dataProvider hostile_attributes
	 * @param array<string, mixed> $attributes Hostile attributes.
	 */
	public function test_hostile_values_and_unknown_keys_are_dropped( array $attributes ): void {
		$this->assertSame( array(), BlockAttributes::options( $attributes ) );
	}

	public function test_digit_strings_are_accepted_for_the_numeric_overrides(): void {
		$this->assertSame(
			array(
				'settings' => array(
					'columns' => 5,
					'gap'     => 10,
				),
			),
			BlockAttributes::options(
				array(
					'columns' => '5',
					'gap'     => '10',
				)
			)
		);
	}

	public function test_eager_true_is_kept_as_an_explicit_choice(): void {
		$this->assertSame( array( 'eager' => true ), BlockAttributes::options( array( 'eager' => true ) ) );
	}

	/**
	 * @return iterable<string, array{mixed, string|null}>
	 */
	public static function anchors(): iterable {
		yield 'plain' => array( 'pictures', 'pictures' );
		yield 'with punctuation' => array( 'my-gallery_2:a.b', 'my-gallery_2:a.b' );
		yield 'starts with digit' => array( '1st', null );
		yield 'space' => array( 'my gallery', null );
		yield 'quote' => array( 'a"onmouseover="x', null );
		yield 'markup' => array( '"><img src=x onerror=alert(1)>', null );
		yield 'empty' => array( '', null );
		yield 'too long' => array( 'a' . str_repeat( 'b', 100 ), null );
		yield 'array' => array( array( 'x' ), null );
		yield 'null' => array( null, null );
	}

	/**
	 * @dataProvider anchors
	 * @param mixed $value Raw attribute.
	 */
	public function test_the_anchor_is_a_conservative_id( $value, ?string $expected ): void {
		$this->assertSame( $expected, BlockAttributes::anchor( array( 'anchor' => $value ) ) );
	}

	public function test_a_missing_anchor_is_null(): void {
		$this->assertNull( BlockAttributes::anchor( array() ) );
	}
}
