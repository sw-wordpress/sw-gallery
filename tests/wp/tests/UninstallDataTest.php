<?php
/**
 * M5-06: deactivation leaves everything, the default uninstall preserves ALL data, the opt-in uninstall removes ONLY
 * plugin data (galleries of every status with their meta, the item table, plugin options and transients) and never an
 * attachment, a foreign table, a foreign option or content of other post types. Runs the real uninstall.php the way
 * WordPress does (WP_UNINSTALL_PLUGIN defined, no logged-in user, as under WP-CLI).
 *
 * @package sw-gallery
 */

use SWGallery\WordPress\Database\Migrator;
use SWGallery\WordPress\Database\Schema;
use SWGallery\WordPress\Infrastructure\WpLogger;
use SWGallery\WordPress\Lifecycle;
use SWGallery\WordPress\PostTypes;
use SWGallery\WordPress\Rendering\MediaEpoch;
use SWGallery\WordPress\Repository\WpdbGalleryItemRepository;
use SWGallery\WordPress\Settings\PluginSettings;

class UninstallDataTest extends SwgTestCase {

	/** @var list<int> */
	private array $blogs = array();

	/** @var list<string> */
	private array $decoy_tables = array();

	public function tear_down(): void {
		global $wpdb;
		foreach ( $this->decoy_tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB
		}
		$this->decoy_tables = array();
		foreach ( $this->blogs as $blog ) {
			wp_delete_site( $blog );
		}
		$this->blogs = array();
		foreach ( array( 'swgx_settings', 'xswg_settings', 'swgallery_x', 'other_plugin_option' ) as $option ) {
			delete_option( $option );
		}
		foreach ( array( 'swg_cache_probe', 'other_swg_cache', 'swgx_cache' ) as $transient ) {
			delete_transient( $transient ); // DDL commits implicitly: nothing of this test is rolled back.
		}
		foreach ( array( PluginSettings::OPTION, WpLogger::OPTION, MediaEpoch::OPTION, Migrator::LOCK_OPTION, Schema::OPTION ) as $option ) {
			delete_option( $option );
		}
		delete_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
	}

	private function decoy_table( string $table ): void {
		global $wpdb;
		$this->use_real_ddl();
		$wpdb->query( "CREATE TABLE `{$table}` (id INT NOT NULL PRIMARY KEY, note VARCHAR(20)) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "INSERT INTO `{$table}` VALUES (1, 'keep')" ); // phpcs:ignore WordPress.DB
		$this->decoy_tables[] = $table;
	}

	/**
	 * A realistic site: galleries in every status (trash and private included) with items, settings, cover and an
	 * attached attachment, plus everything the plugin keeps in options and transients, plus decoys that must survive.
	 *
	 * @return array{galleries: list<int>, attachments: list<int>, decoy: array<string, int>}
	 */
	private function fixture(): array {
		global $wpdb;
		$this->use_real_ddl();
		Schema::migrate();
		$attachments = array(
			self::factory()->attachment->create_object( 'a.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) ),
			self::factory()->attachment->create_object( 'b.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) ),
		);
		$galleries   = array();
		foreach ( array( 'publish', 'draft', 'private', 'pending', 'trash' ) as $status ) {
			$id = self::factory()->post->create(
				array(
					'post_type'   => PostTypes::GALLERY,
					'post_status' => 'trash' === $status ? 'publish' : $status,
					'post_title'  => "G $status",
				)
			);
			update_post_meta( $id, PostTypes::META_LAYOUT, 'grid' );
			update_post_meta( $id, PostTypes::META_SETTINGS, '{"columns":3}' );
			update_post_meta( $id, PostTypes::META_REV, 4 );
			update_post_meta( $id, '_thumbnail_id', $attachments[0] );
			foreach ( $attachments as $attachment ) {
				( new WpdbGalleryItemRepository() )->append( $id, $attachment );
			}
			if ( 'trash' === $status ) {
				wp_trash_post( $id );
			}
			$galleries[] = $id;
		}
		// An attachment uploaded "to" a gallery (post_parent): it must only be re-parented, never deleted.
		wp_update_post(
			array(
				'ID'          => $attachments[1],
				'post_parent' => $galleries[0],
			)
		);
		update_post_meta( $attachments[0], '_wp_attachment_image_alt', 'Alt kept' );

		update_option( PluginSettings::OPTION, '{"schemaVersion":1,"itemsPerPage":30}', false );
		update_option( WpLogger::OPTION, array( array( 'time' => 'x', 'code' => 'swg_x' ) ), false );
		update_option( MediaEpoch::OPTION, 'abc', false );
		add_option( Migrator::LOCK_OPTION, time() + 60, '', false );
		set_transient( 'swg_cache_probe', 'ours', 600 );

		// Decoys: similar names that are NOT ours, content of other types, a foreign table.
		update_option( 'swgx_settings', 'keep', false );
		update_option( 'xswg_settings', 'keep', false );
		update_option( 'swgallery_x', 'keep', false );
		update_option( 'other_plugin_option', 'keep', false );
		set_transient( 'other_swg_cache', 'keep', 600 );
		set_transient( 'swgx_cache', 'keep', 600 );
		$page    = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'A page' ) );
		$post    = self::factory()->post->create( array( 'post_title' => 'A post' ) );
		update_post_meta( $post, '_swg_revision', 'foreign meta on a post is not ours to judge' );
		$this->decoy_table( $wpdb->prefix . 'swg_gallery_items_backup' );
		$this->decoy_table( $wpdb->prefix . 'swg_gallery_items2' );
		$this->decoy_table( $wpdb->prefix . 'other_plugin_table' );

		return array(
			'galleries'   => $galleries,
			'attachments' => $attachments,
			'decoy'       => array(
				'page' => $page,
				'post' => $post,
			),
		);
	}

	/** Runs uninstall.php like WordPress: constant defined, no user context. */
	private function run_uninstall_php(): void {
		wp_set_current_user( 0 );
		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'sw-gallery/sw-gallery.php' );
		require dirname( __DIR__, 3 ) . '/uninstall.php';
	}

	/** @return array<string, mixed> */
	private function snapshot( array $fixture ): array {
		global $wpdb;
		$items = Schema::items_table();
		$ids   = implode( ',', array_map( 'intval', array_merge( $fixture['galleries'], $fixture['attachments'], array_values( $fixture['decoy'] ) ) ) );
		return array(
			'posts'     => $wpdb->get_results( "SELECT ID, post_type, post_status, post_parent, post_title FROM {$wpdb->posts} WHERE ID IN ({$ids}) ORDER BY ID", ARRAY_A ), // phpcs:ignore WordPress.DB
			'meta'      => $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$ids}) ORDER BY meta_id", ARRAY_A ), // phpcs:ignore WordPress.DB
			'items'     => $this->table_exists( $items ) ? $wpdb->get_results( "SELECT gallery_id, attachment_id, position FROM `{$items}` ORDER BY id", ARRAY_A ) : null, // phpcs:ignore WordPress.DB
			'options'   => $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%swg%' AND option_name NOT LIKE '%transient_timeout%' ORDER BY option_name", ARRAY_A ), // phpcs:ignore WordPress.DB
			'decoys'    => array_map( fn( string $t ): bool => $this->table_exists( $t ), $this->decoy_tables ),
		);
	}

	public function test_deactivation_leaves_everything_untouched(): void {
		$fixture = $this->fixture();
		update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '1' );
		$before = $this->snapshot( $fixture );

		Lifecycle::deactivate();

		$this->assertSame( $before, $this->snapshot( $fixture ) );
	}

	public function test_the_default_uninstall_keeps_every_byte_of_data(): void {
		$fixture = $this->fixture();
		$before  = $this->snapshot( $fixture );
		$this->assertNotEmpty( $before['items'] );

		$this->run_uninstall_php();

		$this->assertSame( $before, $this->snapshot( $fixture ), 'Galleries, meta, items, options, attachments and decoys are all unchanged.' );
		$this->assertSame( 'ours', get_transient( 'swg_cache_probe' ) );
	}

	public function test_the_opt_in_uninstall_removes_only_plugin_data(): void {
		global $wpdb;
		$fixture = $this->fixture();
		update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '1' );

		$this->run_uninstall_php();

		foreach ( $fixture['galleries'] as $gallery ) {
			$this->assertNull( get_post( $gallery ), 'Gallery ' . $gallery . ' of every status is gone (trash and private included).' );
			$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $gallery ) ), 'Its meta is gone.' ); // phpcs:ignore WordPress.DB
		}
		$this->assertFalse( $this->table_exists( Schema::items_table() ), 'The item table is dropped.' );
		foreach ( array( Schema::OPTION, WpLogger::OPTION, PluginSettings::OPTION, MediaEpoch::OPTION, Migrator::LOCK_OPTION, Lifecycle::OPTION_DELETE_ON_UNINSTALL ) as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' is removed.' );
		}
		wp_cache_flush(); // The uninstall deletes transient rows directly; a real uninstall request has no stale object cache.
		$this->assertFalse( get_transient( 'swg_cache_probe' ) );

		foreach ( $fixture['attachments'] as $attachment ) {
			$this->assertInstanceOf( WP_Post::class, get_post( $attachment ), 'Attachments always survive.' );
			$this->assertSame( 'attachment', get_post_type( $attachment ) );
		}
		$this->assertSame( 'Alt kept', get_post_meta( $fixture['attachments'][0], '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'page', get_post_type( $fixture['decoy']['page'] ) );
		$this->assertSame( 'post', get_post_type( $fixture['decoy']['post'] ) );
		$this->assertSame( 'foreign meta on a post is not ours to judge', get_post_meta( $fixture['decoy']['post'], '_swg_revision', true ) );
		foreach ( array( 'swgx_settings', 'xswg_settings', 'swgallery_x', 'other_plugin_option' ) as $option ) {
			$this->assertSame( 'keep', get_option( $option ), $option . ' is not ours.' );
		}
		$this->assertSame( 'keep', get_transient( 'other_swg_cache' ) );
		$this->assertSame( 'keep', get_transient( 'swgx_cache' ) );
		foreach ( $this->decoy_tables as $table ) {
			$this->assertTrue( $this->table_exists( $table ), $table . ' survives.' );
			$this->assertSame( 'keep', $wpdb->get_var( "SELECT note FROM `{$table}` WHERE id = 1" ) ); // phpcs:ignore WordPress.DB
		}
	}

	public function test_the_opt_in_uninstall_is_repeatable_and_survives_a_missing_table(): void {
		$this->fixture();
		update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '1' );
		$this->run_uninstall_php();

		update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '1' );
		$this->run_uninstall_php();

		$this->assertFalse( $this->table_exists( Schema::items_table() ) );
		$this->assertFalse( get_option( Schema::OPTION ) );
	}

	public function test_uninstall_php_does_nothing_without_the_wordpress_uninstall_constant(): void {
		$php    = PHP_BINARY;
		$file   = escapeshellarg( dirname( __DIR__, 3 ) . '/uninstall.php' );
		$output = array();
		$code   = 0;
		exec( escapeshellarg( $php ) . ' ' . $file . ' 2>&1', $output, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		$this->assertSame( 0, $code );
		$this->assertSame( array(), $output, 'The guard exits silently: no autoloading, no queries, no output.' );
	}

	public function test_multisite_removes_only_the_sites_that_opted_in_and_never_foreign_tables(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		global $wpdb;
		$this->use_real_ddl();
		$opted         = self::factory()->blog->create();
		$kept          = self::factory()->blog->create();
		$this->blogs[] = $opted;
		$this->blogs[] = $kept;
		$state         = array();
		foreach ( array( $opted, $kept ) as $blog ) {
			switch_to_blog( $blog );
			Schema::migrate();
			$gallery_private = self::factory()->post->create( array( 'post_type' => PostTypes::GALLERY, 'post_status' => 'private' ) );
			$gallery_trash   = self::factory()->post->create( array( 'post_type' => PostTypes::GALLERY ) );
			wp_trash_post( $gallery_trash );
			$attachment = self::factory()->attachment->create_object( 'm.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
			( new WpdbGalleryItemRepository() )->append( $gallery_private, $attachment );
			update_option( PluginSettings::OPTION, '{"schemaVersion":1}', false );
			$decoy = $wpdb->prefix . 'swg_gallery_items_backup';
			$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$decoy}` (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB
			$state[ $blog ] = array( $gallery_private, $gallery_trash, $attachment, $decoy );
			if ( $blog === $opted ) {
				update_option( Lifecycle::OPTION_DELETE_ON_UNINSTALL, '1' );
			}
			restore_current_blog();
		}
		update_site_option( 'swg_network_probe', 'keep' );
		$network_before = get_site_option( 'active_sitewide_plugins' );

		$this->run_uninstall_php();

		switch_to_blog( $opted );
		[ $private, $trash, $attachment, $decoy ] = $state[ $opted ];
		$this->assertNull( get_post( $private ) );
		$this->assertNull( get_post( $trash ), 'The trashed gallery of the opted-in site is gone.' );
		$this->assertInstanceOf( WP_Post::class, get_post( $attachment ) );
		$this->assertFalse( $this->table_exists( Schema::items_table() ) );
		$this->assertFalse( get_option( PluginSettings::OPTION ), 'Its own options table was cleaned.' );
		$this->assertTrue( $this->table_exists( $decoy ), 'The decoy table of that site survives.' );
		$wpdb->query( "DROP TABLE IF EXISTS `{$decoy}`" ); // phpcs:ignore WordPress.DB
		restore_current_blog();

		switch_to_blog( $kept );
		[ $private, $trash, $attachment, $decoy ] = $state[ $kept ];
		$this->assertInstanceOf( WP_Post::class, get_post( $private ) );
		$this->assertInstanceOf( WP_Post::class, get_post( $trash ) );
		$this->assertTrue( $this->table_exists( Schema::items_table() ) );
		$this->assertSame( 1, ( new WpdbGalleryItemRepository() )->count( $private ) );
		$this->assertNotFalse( get_option( PluginSettings::OPTION ), 'The preserving site keeps its settings.' );
		$wpdb->query( "DROP TABLE IF EXISTS `{$decoy}`" ); // phpcs:ignore WordPress.DB
		restore_current_blog();

		$this->assertSame( 'keep', get_site_option( 'swg_network_probe' ), 'Network options are not touched (the plugin has none).' );
		$this->assertSame( $network_before, get_site_option( 'active_sitewide_plugins' ) );
		delete_site_option( 'swg_network_probe' );
		$this->assertSame( get_current_blog_id(), (int) $wpdb->blogid );
	}
}
