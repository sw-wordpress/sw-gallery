<?php
/**
 * WordPress test suite configuration. Uses ONLY the private throw-away MariaDB instance (bin/test-db.sh) or the
 * database service of CI (SWG_TEST_DB_* variables), never the development site's database.
 *
 * @package sw-gallery
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

define( 'ABSPATH', __DIR__ . '/vendor/johnpbloch/wordpress-core/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

$swg_db_dir  = getenv( 'SWG_TEST_DB_DIR' ) ? getenv( 'SWG_TEST_DB_DIR' ) : '/tmp/swg-test-db';
$swg_db_port = getenv( 'SWG_TEST_DB_PORT' );
if ( ! $swg_db_port && is_readable( $swg_db_dir . '/port' ) ) {
	$swg_db_port = trim( (string) file_get_contents( $swg_db_dir . '/port' ) );
}

define( 'DB_NAME', getenv( 'SWG_WP_TEST_DB_NAME' ) ? getenv( 'SWG_WP_TEST_DB_NAME' ) : 'swg_wp_tests' );
define( 'DB_USER', getenv( 'SWG_TEST_DB_USER' ) ? getenv( 'SWG_TEST_DB_USER' ) : 'swg' );
define( 'DB_PASSWORD', getenv( 'SWG_TEST_DB_PASSWORD' ) ? getenv( 'SWG_TEST_DB_PASSWORD' ) : 'swg' );
define( 'DB_HOST', ( getenv( 'SWG_TEST_DB_HOST' ) ? getenv( 'SWG_TEST_DB_HOST' ) : '127.0.0.1' ) . ':' . ( $swg_db_port ? $swg_db_port : '3306' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'sw-gallery Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
