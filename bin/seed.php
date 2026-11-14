<?php
/**
 * Dev-only fixture seeder (never shipped): `wp eval-file bin/seed.php -- --items=5000 --galleries=2 [--force] [--no-files]`
 * or `wp eval-file bin/seed.php -- --purge` to remove what a previous run created.
 *
 * Creates N cheap real image attachments (batched INSERTs, a handful of tiny real files in uploads/swg-seed reused by
 * every attachment) and M galleries that each contain all N images. Refuses to run unless WP_ENVIRONMENT_TYPE is
 * local, development or staging (or --force). See docs/performance.md for the trade-offs.
 *
 * @package sw-gallery
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run through WP-CLI: wp eval-file bin/seed.php -- --items=N --galleries=M\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/tests/wp/support/SwgSeeder.php';

$swg_args = array();
foreach ( array_slice( $GLOBALS['argv'] ?? array(), 1 ) as $swg_arg ) {
	if ( 1 === preg_match( '/^--([a-z-]+)(?:=(.*))?$/', (string) $swg_arg, $swg_m ) ) {
		$swg_args[ $swg_m[1] ] = $swg_m[2] ?? true;
	}
}
if ( isset( $swg_args['no-files'] ) ) {
	$swg_args['with-files'] = false;
}

try {
	SwgSeeder::assert_allowed( isset( $swg_args['force'] ) );
	if ( isset( $swg_args['purge'] ) ) {
		$swg_result = SwgSeeder::purge();
		echo 'Removed ' . $swg_result['galleries'] . ' seeded galleries and ' . $swg_result['attachments'] . " seeded attachments.\n";
	} else {
		$swg_result = SwgSeeder::run( $swg_args );
		echo sprintf(
			"Seeded %d attachments, %d galleries, %d items in %.2fs. First gallery id: %d (shortcode [sw_gallery id=\"%d\"]).\n",
			$swg_result['attachments'],
			$swg_result['galleries'],
			$swg_result['items'],
			$swg_result['seconds'],
			$swg_result['first_gallery'],
			$swg_result['first_gallery']
		);
	}
} catch ( \Throwable $swg_e ) {
	fwrite( STDERR, $swg_e->getMessage() . "\n" );
	exit( 1 );
}
