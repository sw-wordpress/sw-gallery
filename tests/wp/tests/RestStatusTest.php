<?php
/**
 * GET /swg/v1/status: role matrix, shape, bounded counts, honest incompleteness and no leaked internals.
 *
 * @package sw-gallery
 */

use SWGallery\Core\Domain\Gallery\GalleryStatus;
use SWGallery\Core\Logging\LogLevel;
use SWGallery\Tests\Support\RestFixtures;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Infrastructure\Services;
use SWGallery\WordPress\Rest\StatusReport;

class RestStatusTest extends SwgTestCase {

	use RestFixtures;

	private int $admin;

	/** @var list<string> */
	private array $dirs = array();

	public function set_up(): void {
		parent::set_up();
		$this->bootRest();
		$this->admin = $this->makeUser( 'administrator' );
		Services::logger()->clear();
	}

	public function tear_down(): void {
		Services::logger()->clear();
		foreach ( $this->dirs as $dir ) {
			array_map( 'unlink', glob( $dir . '/{,.vite/}*.*', GLOB_BRACE ) ?: array() );
			@rmdir( $dir . '/.vite' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test clean-up.
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- test clean-up.
		}
		$this->shutdownRest();
		parent::tear_down();
	}

	public function test_role_matrix(): void {
		$expected = array(
			'administrator' => 200,
			'editor'        => 403,
			'author'        => 403,
			'contributor'   => 403,
			'subscriber'    => 403,
			'anonymous'     => 401,
		);
		foreach ( $expected as $role => $status ) {
			$user = 'anonymous' === $role ? 0 : $this->makeUser( $role );
			$this->assertSame( $status, $this->call( 'GET', '/status', array(), $user )->get_status(), $role );
		}
	}

	public function test_the_shape_of_a_healthy_site(): void {
		$this->makeGallery( $this->admin, GalleryStatus::Draft );
		$this->makeGallery( $this->admin, GalleryStatus::Draft );
		$this->makeGallery( $this->admin, GalleryStatus::Publish );

		$data = $this->data( $this->call( 'GET', '/status', array(), $this->admin ) );

		$this->assertEqualsCanonicalizing( array( 'schema', 'counts', 'recentErrors', 'versions', 'multisite', 'cache', 'adminBuild', 'hints' ), array_keys( $data ) );
		$this->assertSame(
			array(
				'stored'           => Schema::CURRENT,
				'current'          => Schema::CURRENT,
				'migrationPending' => false,
				'newerThanCode'    => false,
				'itemTablePresent' => true,
			),
			$data['schema']
		);
		$this->assertSame( 2, $data['counts']['galleries']['draft'] );
		$this->assertSame( 1, $data['counts']['galleries']['publish'] );
		$this->assertSame( 0, $data['counts']['galleries']['trash'] );
		$this->assertSame(
			array(
				'count'  => 0,
				'capped' => false,
			),
			$data['counts']['itemRows']
		);
		$this->assertSame(
			array(
				'count'    => 0,
				'complete' => true,
			),
			$data['counts']['orphanRelations']
		);
		$this->assertSame( array(), $data['recentErrors'] );
		$this->assertSame( SWGALLERY_VERSION, $data['versions']['plugin'] );
		$this->assertSame( get_bloginfo( 'version' ), $data['versions']['wordpress'] );
		$this->assertSame( PHP_VERSION, $data['versions']['php'] );
		$this->assertContains( $data['versions']['database']['type'], array( 'MariaDB', 'MySQL' ) );
		$this->assertNotSame( '', $data['versions']['database']['version'] );
		$this->assertSame( is_multisite(), $data['multisite'] );
		$this->assertSame( array( 'state' => 'none' ), $data['cache'] );
		$this->assertIsBool( $data['adminBuild'] );
		$this->assertSame( 'export_limitation', $data['hints'][0]['key'] );
		$this->assertStringContainsString( 'not exported', $data['hints'][0]['message'] );
	}

	public function test_counts_follow_the_item_table_and_orphans_are_found_read_only(): void {
		global $wpdb;
		$gallery    = $this->makeGallery( $this->admin );
		$attachment = $this->makeAttachment( $this->admin );
		$table      = Schema::items_table();
		$now        = gmdate( 'Y-m-d H:i:s' );
		foreach ( array( $attachment, 999001, 999002 ) as $position => $id ) {
			$wpdb->insert(
				$table,
				array(
					'gallery_id'    => $gallery,
					'attachment_id' => $id,
					'position'      => $position + 1,
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
		}

		$counts = $this->data( $this->call( 'GET', '/status', array(), $this->admin ) )['counts'];

		$this->assertSame( 3, $counts['itemRows']['count'] );
		$this->assertSame(
			array(
				'count'    => 2,
				'complete' => true,
			),
			$counts['orphanRelations']
		);
		$this->assertSame( 3, $this->countItemRows(), 'the status never repairs' );
	}

	public function test_a_capped_table_skips_the_orphan_scan_and_says_so(): void {
		global $wpdb;
		$gallery = $this->makeGallery( $this->admin );
		$table   = Schema::items_table();
		$now     = gmdate( 'Y-m-d H:i:s' );
		foreach ( array( 999011, 999012, 999013 ) as $position => $id ) {
			$wpdb->insert(
				$table,
				array(
					'gallery_id'    => $gallery,
					'attachment_id' => $id,
					'position'      => $position + 1,
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
		}

		$report = ( new StatusReport( Services::logger(), Services::relations(), SWGALLERY_DIR . 'assets/admin', 2 ) )->build();

		$this->assertSame(
			array(
				'count'  => 2,
				'capped' => true,
			),
			$report['counts']['itemRows']
		);
		$this->assertSame(
			array(
				'count'    => null,
				'complete' => false,
			),
			$report['counts']['orphanRelations']
		);
	}

	public function test_the_orphan_scan_stops_at_its_time_budget_and_reports_incomplete(): void {
		global $wpdb;
		$gallery = $this->makeGallery( $this->admin );
		$table   = Schema::items_table();
		$now     = gmdate( 'Y-m-d H:i:s' );
		for ( $i = 1; $i <= 25; ++$i ) {
			$wpdb->insert(
				$table,
				array(
					'gallery_id'    => $gallery,
					'attachment_id' => 990000 + $i,
					'position'      => $i,
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
		}

		$report = ( new StatusReport( Services::logger(), Services::relations(), SWGALLERY_DIR . 'assets/admin', 1000, 10, 0.0 ) )->build();

		$this->assertSame(
			array(
				'count'    => 10,
				'complete' => false,
			),
			$report['counts']['orphanRelations']
		);
	}

	public function test_a_missing_item_table_and_a_pending_migration_are_reported(): void {
		update_option( Schema::OPTION, 0 );
		$this->drop_items_table();

		$data = $this->data( $this->call( 'GET', '/status', array(), $this->admin ) );

		$this->assertFalse( $data['schema']['itemTablePresent'] );
		$this->assertSame( 0, $data['schema']['stored'] );
		$this->assertTrue( $data['schema']['migrationPending'] );
		$this->assertSame( 0, $data['counts']['itemRows']['count'] );
		$this->assertNull( $data['counts']['orphanRelations']['count'] );
		$this->assertFalse( $data['counts']['orphanRelations']['complete'] );

		// Restore the table before the fixtures clean up.
		Schema::migrate();
	}

	public function test_recent_errors_are_capped_newest_first_and_sanitized(): void {
		for ( $i = 1; $i <= 25; ++$i ) {
			Services::logger()->log( LogLevel::Error, 'swg_test_error', array( 'n' => $i, 'path' => '/var/www/secret.php', 'note' => '/etc/passwd' ) );
		}
		Services::logger()->log( LogLevel::Warning, 'swg_not_an_error' );

		$errors = $this->data( $this->call( 'GET', '/status', array(), $this->admin ) )['recentErrors'];

		$this->assertCount( StatusReport::MAX_ERRORS, $errors );
		$this->assertSame( 25, $errors[0]['context']['n'] );
		$this->assertSame( 6, $errors[19]['context']['n'] );
		$this->assertSame( 'swg_test_error', $errors[0]['code'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $errors[0]['time'] );
		$this->assertSame( '[redacted]', $errors[0]['context']['note'] );
		$this->assertArrayNotHasKey( 'path', $errors[0]['context'] );
	}

	public function test_a_valid_admin_build_is_reported_as_a_boolean_without_paths(): void {
		$dir = sys_get_temp_dir() . '/swg-status-build-' . wp_generate_password( 8, false );
		mkdir( $dir . '/.vite', 0777, true );
		$this->dirs[] = $dir;
		file_put_contents( $dir . '/main.js', '//' );
		file_put_contents( $dir . '/style.css', '/**/' );
		file_put_contents(
			$dir . '/.vite/manifest.json',
			(string) wp_json_encode(
				array(
					'src/main.ts' => array( 'file' => 'main.js' ),
					'style.css'   => array( 'file' => 'style.css' ),
				)
			)
		);

		$valid   = ( new StatusReport( Services::logger(), Services::relations(), $dir ) )->build();
		$missing = ( new StatusReport( Services::logger(), Services::relations(), $dir . '/nope' ) )->build();

		$this->assertTrue( $valid['adminBuild'] );
		$this->assertFalse( $missing['adminBuild'] );
		$this->assertStringNotContainsString( $dir, (string) wp_json_encode( $valid ) );
	}

	public function test_the_payload_leaks_no_paths_credentials_or_hosts(): void {
		Services::logger()->log( LogLevel::Error, 'swg_leak_probe', array( 'detail' => ABSPATH . 'wp-config.php' ) );

		$data = $this->call( 'GET', '/status', array(), $this->admin )->get_data();
		$body = (string) wp_json_encode( $data );

		$needles = array( ABSPATH, WP_CONTENT_DIR, SWGALLERY_DIR, 'SELECT ', 'wp-config', 'Stack trace' );
		foreach ( array( 'DB_HOST', 'DB_PASSWORD', 'DB_USER', 'DB_NAME', 'AUTH_KEY', 'SECURE_AUTH_KEY' ) as $constant ) {
			if ( ! defined( $constant ) || '' === (string) constant( $constant ) ) {
				continue;
			}
			$value = (string) constant( $constant );
			// Short values (the throw-away database user is `swg`) would match harmless text: compare them as whole values.
			if ( strlen( $value ) >= 8 ) {
				$needles[] = $value;
			}
			array_walk_recursive(
				$data,
				function ( $leaf ) use ( $value, $constant ): void {
					$this->assertNotSame( $value, is_scalar( $leaf ) ? (string) $leaf : null, $constant . ' leaked as a value' );
				}
			);
		}
		foreach ( $needles as $needle ) {
			$this->assertStringNotContainsString( $needle, $body );
		}
		$this->assertStringNotContainsString( $GLOBALS['wpdb']->prefix . 'posts', $body );
	}

	public function test_the_routes_of_this_wave_are_registered_with_the_documented_methods(): void {
		$routes  = rest_get_server()->get_routes( 'swg/v1' );
		$methods = array();
		foreach ( array( '/swg/v1/layouts', '/swg/v1/media', '/swg/v1/settings', '/swg/v1/status' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
			foreach ( $routes[ $route ] as $handler ) {
				$this->assertIsCallable( $handler['permission_callback'], $route );
				$this->assertNotSame( '__return_true', $handler['permission_callback'], $route );
				$methods[ $route ][] = implode( ',', array_keys( (array) $handler['methods'] ) );
			}
		}
		$this->assertSame( array( 'GET' ), $methods['/swg/v1/layouts'] );
		$this->assertSame( array( 'GET', 'POST' ), $methods['/swg/v1/media'] );
		$this->assertSame( array( 'GET', 'PATCH' ), $methods['/swg/v1/settings'] );
		$this->assertSame( array( 'GET' ), $methods['/swg/v1/status'] );
	}
}
