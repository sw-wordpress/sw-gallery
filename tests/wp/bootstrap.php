<?php
/**
 * Bootstrap for the WordPress integration tests. When the throw-away database is unreachable the WordPress test library
 * is not loaded and every test skips itself (see support/SwgTestCase.php).
 *
 * @package sw-gallery
 */

$swg_wp_loader = require __DIR__ . '/vendor/autoload.php';
// Shared test support (contracts and fakes) of the unit suite.
$swg_wp_loader->addPsr4( 'SWGallery\\Tests\\Support\\', dirname( __DIR__ ) . '/Support/' );

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
define( 'SWGALLERY_AUTOLOADER', __DIR__ . '/vendor/autoload.php' );

/**
 * Whether the configured test database accepts connections.
 */
function swg_wp_tests_db_reachable(): bool {
	if ( ! function_exists( 'mysqli_init' ) ) {
		return false;
	}
	$dir  = getenv( 'SWG_TEST_DB_DIR' ) ? getenv( 'SWG_TEST_DB_DIR' ) : '/tmp/swg-test-db';
	$port = getenv( 'SWG_TEST_DB_PORT' );
	if ( ! $port && is_readable( $dir . '/port' ) ) {
		$port = trim( (string) file_get_contents( $dir . '/port' ) );
	}
	$link = mysqli_init();
	if ( false === $link ) {
		return false;
	}
	mysqli_options( $link, MYSQLI_OPT_CONNECT_TIMEOUT, 3 );
	mysqli_report( MYSQLI_REPORT_OFF );
	$ok = @mysqli_real_connect(
		$link,
		getenv( 'SWG_TEST_DB_HOST' ) ? getenv( 'SWG_TEST_DB_HOST' ) : '127.0.0.1',
		getenv( 'SWG_TEST_DB_USER' ) ? getenv( 'SWG_TEST_DB_USER' ) : 'swg',
		getenv( 'SWG_TEST_DB_PASSWORD' ) ? getenv( 'SWG_TEST_DB_PASSWORD' ) : 'swg',
		getenv( 'SWG_WP_TEST_DB_NAME' ) ? getenv( 'SWG_WP_TEST_DB_NAME' ) : 'swg_wp_tests',
		$port ? (int) $port : 3306
	);
	if ( $ok ) {
		mysqli_close( $link );
	}
	return (bool) $ok;
}

define( 'SWG_WP_DB_AVAILABLE', swg_wp_tests_db_reachable() );

if ( SWG_WP_DB_AVAILABLE ) {
	$swg_tests_dir = __DIR__ . '/vendor/wp-phpunit/wp-phpunit';
	require_once $swg_tests_dir . '/includes/functions.php';

	tests_add_filter(
		'muplugins_loaded',
		static function (): void {
			require dirname( __DIR__, 2 ) . '/sw-gallery.php';
			// The pinned mcp-adapter (dev dependency of this suite); SWG_WP_NO_MCP_ADAPTER=1 runs without it (absence tests).
			if ( ! getenv( 'SWG_WP_NO_MCP_ADAPTER' ) && function_exists( 'wp_register_ability' ) ) {
				define( 'WP_MCP_AUTOLOAD', false );
				require __DIR__ . '/vendor/wordpress/mcp-adapter/mcp-adapter.php';
			}
		}
	);

	require $swg_tests_dir . '/includes/bootstrap.php';

	// The adapter initialises once per process on the first `rest_api_init` and hooks its transport routes then; the test
	// case restores the hooks after every test, so that first initialisation must happen before the first test.
	if ( ! getenv( 'SWG_WP_NO_MCP_ADAPTER' ) && function_exists( 'wp_register_ability' ) ) {
		rest_get_server();
		// Building the REST routes created the plugin services and announced the extension points once; give the tests a
		// pristine "once per request" state.
		\SWGallery\WordPress\Infrastructure\Services::reset();
		unset( $GLOBALS['wp_actions']['swg_register_layouts'], $GLOBALS['wp_actions']['swg_register_import_providers'] );
	}
} else {
	fwrite( STDERR, "sw-gallery WordPress tests: test database unreachable (start it with bin/test-db.sh start); tests will be skipped.\n" );
}

require_once __DIR__ . '/support/SwgTestCase.php';
